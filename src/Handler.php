<?php

namespace Swerve\Yii;

use phasync\Util\Synchronized;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\WorkerStoppingException;
use Yiisoft\Di\StateResetter;
use Yiisoft\ErrorHandler\ErrorHandler;
use Yiisoft\ErrorHandler\Middleware\ErrorCatcher;
use Yiisoft\Session\Flash\Flash;
use Yiisoft\Session\Flash\FlashInterface;
use Yiisoft\Yii\Http\Application;
use Yiisoft\Yii\Http\Handler\ThrowableHandler;
use Yiisoft\Yii\Runner\ApplicationRunner;

/**
 * A Yii 3 application as a PSR-15 request handler, which the `yii` adapter's entry ({@see entry()})
 * serves through swerve-psr15's Bridge; a swerve.php run with `--adapter=swerve` may do the same,
 * for other settings:
 *
 *     return Swerve\Psr15\Bridge::wrap(new Swerve\Yii\Handler(__DIR__));
 *
 * Once per worker, the constructor builds the configuration and the container, registers the
 * application's ErrorHandler, runs the bootstrap group and starts the Application, as Yii's
 * RoadRunner runner does. Per request, the Application handles the request; then the AfterEmit
 * event is dispatched, StateResetter resets the services that keep request state (CurrentRoute,
 * RequestProvider, the session, the view...), and PHP's session id and $_SESSION are cleared.
 *
 * Requests take turns, one at a time per worker: those services, and PHP's native session,
 * are shared by every request of the worker. See docs/concurrency.md.
 */
final class Handler implements RequestHandlerInterface
{
    private readonly Application $application;
    private readonly ContainerInterface $container;
    private readonly ErrorCatcher $errorCatcher;
    private readonly ?\Closure $resetFlash;

    /** The WorkerStoppingException the ErrorCatcher middleware caught in the request in flight */
    private static ?WorkerStoppingException $stopping = null;

    /**
     * @param string      $root        the application's root directory, where composer.json is
     * @param bool|null   $debug       debug mode; null reads APP_DEBUG from the environment
     * @param string|null $environment the configuration environment; null reads APP_ENV
     */
    public function __construct(string $root, ?bool $debug = null, ?string $environment = null)
    {
        $debug ??= \filter_var(self::env('APP_DEBUG') ?? false, \FILTER_VALIDATE_BOOLEAN);
        $environment ??= self::env('APP_ENV');

        $runner = new class($root, $debug, $debug, $environment, 'bootstrap-web', 'events-web', 'di-web', 'di-providers-web', 'di-delegates-web', 'di-tags-web', 'params-web', ['params'], ['events']) extends ApplicationRunner {
            public function run(): void
            {
                $this->runBootstrap();
                $this->checkEvents();
            }
        };
        $container = $runner->getContainer();

        $errorHandler = $container->get(ErrorHandler::class);
        if ($debug) {
            $errorHandler->debug();
        }
        $errorHandler->register();

        $runner->run();

        $this->application  = $container->get(Application::class);
        $this->container    = $container;
        $this->errorCatcher = $container->get(ErrorCatcher::class);
        // yiisoft/session's Flash remembers the last session id it saw, and has no reset: in
        // the next request of the same session its messages would not expire (yiisoft/session#2)
        $flash            = \interface_exists(FlashInterface::class) && $container->has(FlashInterface::class) ? $container->get(FlashInterface::class) : null;
        $this->resetFlash = $flash instanceof Flash ? \Closure::bind(function () { $this->sessionId = null; }, $flash, Flash::class) : null;
        $this->application->start();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Synchronized::run($this, function () use ($request) {
            $request = $request->withAttribute('applicationStartTime', \microtime(true));
            try {
                $response = $this->application->handle($request);
                if (null !== $stopping = self::$stopping) {
                    self::$stopping = null;

                    throw $stopping;
                }

                return $response;
            } catch (WorkerStoppingException $e) {
                throw $e; // the worker stops: swerve answers the request (a 503 when nothing was sent yet)
            } catch (\Throwable $e) {
                return $response = $this->errorCatcher->process($request, new ThrowableHandler($e));
            } finally {
                $this->application->afterEmit($response ?? null);
                // Fetched each time: it resets the services built so far
                $this->container->get(StateResetter::class)->reset();
                $this->resetFlash?->__invoke();
                // PHP keeps the last session id and $_SESSION: the next visitor without a
                // session cookie would get the previous visitor's session
                \session_id('');
                $_SESSION = [];
            }
        });
    }

    /**
     * The ApplicationError listener (config/events-web.php): the ErrorCatcher middleware turns a
     * stopping worker's WorkerStoppingException into a 500, which handle() replaces by throwing it on.
     *
     * @internal
     */
    public static function noteError(\Throwable $e): void
    {
        $e instanceof WorkerStoppingException && self::$stopping = $e;
    }

    /** The skeleton's .env loader fills $_ENV, the process environment getenv(). */
    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? \getenv($name);

        return false === $value || '' === $value ? null : (string) $value;
    }
}
