<?php

declare(strict_types=1);

use App\SwerveTest\Identity;
use phasync\Psr\UnbufferedStream;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Http\WebSocket;
use Yiisoft\Csrf\CsrfTokenInterface;
use Yiisoft\Request\Body\RequestBodyParser;
use Yiisoft\RequestProvider\RequestProviderInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\Route;
use Yiisoft\Session\Flash\FlashInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\User\CurrentUser;

// The routes the tests use, added to the skeleton's own

$text = static function (ResponseFactoryInterface $f, string $body, string $type = 'text/plain'): ResponseInterface {
    $response = $f->createResponse()->withHeader('Content-Type', $type);
    $response->getBody()->write($body);

    return $response;
};
$json = static fn (ResponseFactoryInterface $f, mixed $data) => $text($f, \json_encode($data), 'application/json');
// Wait as a request does for a database: with phasync-ext a plain sleep() lets other requests run
$nap = static fn (float $seconds) => \extension_loaded('phasync') ? \usleep((int) ($seconds * 1e6)) : phasync::sleep($seconds);

return [
    Route::get('/json')->action(fn (ResponseFactoryInterface $f) => $json($f, ['framework' => 'yii', 'ok' => true])),
    Route::get('/csrf')->action(fn (ResponseFactoryInterface $f, CsrfTokenInterface $token) => $text($f, $token->getValue())),
    Route::post('/form')->action(fn (ResponseFactoryInterface $f, ServerRequestInterface $r) => $json($f, $r->getParsedBody())),
    Route::post('/echo')
        ->middleware(RequestBodyParser::class)
        ->action(fn (ResponseFactoryInterface $f, ServerRequestInterface $r) => $json($f, $r->getParsedBody())),
    Route::post('/upload')->action(function (ResponseFactoryInterface $f, ServerRequestInterface $r) use ($json) {
        $files = [];
        foreach ($r->getUploadedFiles() as $name => $file) {
            $files[$name] = [$file->getClientFilename(), $file->getSize(), \md5((string) $file->getStream())];
        }

        return $json($f, ['fields' => $r->getParsedBody(), 'files' => $files]);
    }),

    // Isolation: each request keeps its value in the request, the current route, the request
    // provider, the session and the logged-in user, waits, and reads them back
    Route::get('/isolation/{v}')->action(function (
        ResponseFactoryInterface $f,
        ServerRequestInterface $r,
        CurrentRoute $route,
        RequestProviderInterface $provider,
        SessionInterface $session,
        CurrentUser $user,
    ) use ($json, $nap) {
        $v = $route->getArgument('v');
        $r = $r->withAttribute('v', $v);
        $session->set('v', $v);
        $user->login(new Identity($v));
        $nap(0.3);

        return $json($f, [
            'attribute' => $r->getAttribute('v'),
            'route'     => $route->getArgument('v'),
            'provider'  => $provider->get()->getUri()->getPath(),
            'session'   => $session->get('v'),
            'user'      => $user->getId(),
        ]);
    }),

    // Sessions
    Route::get('/session')->action(fn (ResponseFactoryInterface $f, SessionInterface $s) => $json($f, ['id' => $s->getId(), 'v' => $s->get('v')])),
    Route::get('/counter')->action(function (ResponseFactoryInterface $f, SessionInterface $s) use ($json) {
        $s->set('n', $s->get('n', 0) + 1);

        return $json($f, ['n' => $s->get('n'), 'v' => $s->get('v'), 'id' => $s->getId(), 'pid' => \getmypid()]);
    }),
    Route::get('/flash/set')->action(function (ResponseFactoryInterface $f, FlashInterface $flash) use ($text) {
        $flash->set('info', 'Saved');

        return $text($f, 'set');
    }),
    Route::get('/flash/get')->action(fn (ResponseFactoryInterface $f, FlashInterface $flash) => $text($f, $flash->get('info') ?? '(none)')),
    Route::get('/login/{name}')->action(function (ResponseFactoryInterface $f, CurrentRoute $route, CurrentUser $user) use ($text) {
        $user->login(new Identity($route->getArgument('name')));

        return $text($f, 'logged in');
    }),
    Route::get('/whoami')->action(fn (ResponseFactoryInterface $f, CurrentUser $user) => $text($f, $user->isGuest() ? '(guest)' : $user->getId())),
    Route::get('/logout')->action(function (ResponseFactoryInterface $f, CurrentUser $user) use ($text) {
        $user->logout();

        return $text($f, 'logged out');
    }),

    // Streaming, WebSockets, a slow request, a PHP warning and the worker's memory
    Route::get('/stream')->action(function (ResponseFactoryInterface $f) use ($nap) {
        $body = new UnbufferedStream(1, 10);
        phasync::go(function () use ($body, $nap) {
            $body->append("first\n");
            $nap(1);
            $body->append("last\n");
            $body->end();
        });

        return $f->createResponse()->withHeader('Content-Type', 'text/plain')->withBody($body);
    }),
    Route::get('/ws')->action(fn (ServerRequestInterface $r) => WebSocket::from($r, function (WebSocket $ws) {
        foreach ($ws as $message) {
            $ws->send("echo: $message");
        }
    })),
    Route::get('/slow')->action(function (ResponseFactoryInterface $f) use ($text, $nap) {
        $nap(1);

        return $text($f, 'done');
    }),
    Route::get('/warning')->action(function (ResponseFactoryInterface $f) use ($text) {
        \trigger_error('Something is off', \E_USER_WARNING);

        return $text($f, 'not reached');
    }),
    Route::get('/memory')->action(fn (ResponseFactoryInterface $f) => $text($f, (string) \memory_get_usage())),
];
