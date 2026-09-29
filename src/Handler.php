<?php

namespace Swerve\Yii;

use phasync\Util\Pool;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yiisoft\Config\ConfigInterface;
use Yiisoft\Di\ServiceProviderInterface;
use Yiisoft\Di\StateResetter;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\Session\Flash\Flash;
use Yiisoft\Session\Flash\FlashInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\Http\Application;
use Yiisoft\Yii\Http\Handler\ThrowableHandler;
use Yiisoft\Yii\Runner\ApplicationRunner;

/**
 * A Yii 3 application as swerve's request handler, from the project's swerve.php:
 *
 *     return new Swerve\Yii\Handler(__DIR__);
 *
 * Once per worker, the constructor builds the configuration and a container, registers the
 * application's ErrorHandler, runs the bootstrap group and starts the Application, as Yii's
 * RoadRunner runner does.
 *
 * Per request, the request borrows an application of its own (a container, with its services)
 * from a pool, which builds more, up to $applications, while requests overlap (with phasync-ext,
 * whenever one waits for I/O). The Application handles the request; then the AfterEmit event is
 * dispatched, StateResetter resets the services that keep request state (CurrentRoute,
 * RequestProvider, the session, the view...), and the application goes back to the pool.
 *
 * PHP's native session is one per process: requests take turns on it, from when one opens the
 * session to when it closes it (TurnTakingSession). Requests that don't use it never wait.
 * See docs/concurrency.md.
 */
final class Handler implements RequestHandlerInterface
{
    /** @var Pool<object{application: Application, container: ContainerInterface, errorCatcher: ErrorCatcher, session: ?TurnTakingSession, resetFlash: ?\Closure}> */
    private readonly Pool $applications;

    /** @var Pool<object> the worker's one turn on PHP's native session */
    private readonly Pool $sessionTurn;

    private bool $errorHandlerRegistered = false;

    /**
     * @param string      $root         the application's root directory, where composer.json is
     * @param bool|null   $debug        debug mode; null reads APP_DEBUG from the environment
     * @param string|null $environment  the configuration environment; null reads APP_ENV
     * @param int         $applications the most applications per worker, so the most requests
     *                                  served at once; 1 serves one request at a time
     */
    public function __construct(string $root, ?bool $debug = null, ?string $environment = null, int $applications = 16)
    {
        $debug ??= \filter_var(self::env('APP_DEBUG') ?? false, \FILTER_VALIDATE_BOOLEAN);
        $environment ??= self::env('APP_ENV');

        $this->sessionTurn  = new Pool(static fn () => new \stdClass(), 1);
        $this->applications = new Pool(fn () => $this->boot($root, $debug, $environment), $applications);
        // The first now, at the worker's start; the others when requests overlap
        $this->applications->release($this->applications->borrow());
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $app = $this->applications->borrow();
        try {
            $request = $request->withAttribute('applicationStartTime', \microtime(true));
            try {
                return $response = $app->application->handle($request);
            } catch (\Throwable $e) {
                return $response = $app->errorCatcher->process($request, new ThrowableHandler($e));
            } finally {
                $app->application->afterEmit($response ?? null);
                // Fetched each time: it resets the services built so far
                $app->container->get(StateResetter::class)->reset();
                $app->session?->end();
                $app->resetFlash?->__invoke();
            }
        } finally {
            $this->applications->release($app);
        }
    }

    /** An application: its configuration and container, as yiisoft/yii-runner-roadrunner builds them. */
    private function boot(string $root, bool $debug, ?string $environment): object
    {
        $runner = new class($root, $debug, $debug, $environment, 'bootstrap-web', 'events-web', 'di-web', 'di-providers-web', 'di-delegates-web', 'di-tags-web', 'params-web', ['params'], ['events']) extends ApplicationRunner {
            public function run(): void
            {
                $this->runBootstrap();
                $this->checkEvents();
            }
        };
        // The session service, if any, takes turns on PHP's native session
        $config = $runner->getConfig();
        if (isset($config->get('di-web')[SessionInterface::class])) {
            $turn   = $this->sessionTurn;
            $runner = $runner->withConfig(new class($config, $turn) implements ConfigInterface {
                public function __construct(private readonly ConfigInterface $config, private readonly Pool $turn)
                {
                }

                public function get(string $group): array
                {
                    $groups = $this->config->has($group) ? $this->config->get($group) : [];
                    if ('di-providers-web' === $group) {
                        $groups[] = new class($this->turn) implements ServiceProviderInterface {
                            public function __construct(private readonly Pool $turn)
                            {
                            }

                            public function getDefinitions(): array
                            {
                                return [];
                            }

                            public function getExtensions(): array
                            {
                                return [SessionInterface::class => fn (ContainerInterface $c, SessionInterface $session) => new TurnTakingSession($session, $this->turn)];
                            }
                        };
                    }

                    return $groups;
                }

                public function has(string $group): bool
                {
                    return 'di-providers-web' === $group || $this->config->has($group);
                }
            });
        }
        $container = $runner->getContainer();

        // One ErrorHandler for the worker: PHP's error handler is one per process
        if (!$this->errorHandlerRegistered) {
            $errorHandler = $container->get(ErrorHandler::class);
            if ($debug) {
                $errorHandler->debug();
            }
            $errorHandler->register();
            $this->errorHandlerRegistered = true;
        }

        $runner->run();

        $app               = new \stdClass();
        $app->application  = $container->get(Application::class);
        $app->container    = $container;
        $app->errorCatcher = $container->get(ErrorCatcher::class);
        $session           = $container->has(SessionInterface::class) ? $container->get(SessionInterface::class) : null;
        $app->session      = $session instanceof TurnTakingSession ? $session : null;
        // yiisoft/session's Flash remembers the last session id it saw, and has no reset: in
        // the next request of the same session its messages would not expire (yiisoft/session#2)
        $flash           = \interface_exists(FlashInterface::class) && $container->has(FlashInterface::class) ? $container->get(FlashInterface::class) : null;
        $app->resetFlash = $flash instanceof Flash ? \Closure::bind(function () { $this->sessionId = null; }, $flash, Flash::class) : null;
        $app->application->start();

        return $app;
    }

    /** The skeleton's .env loader fills $_ENV, the process environment getenv(). */
    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? \getenv($name);

        return false === $value || '' === $value ? null : (string) $value;
    }
}
