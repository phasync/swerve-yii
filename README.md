# swerve for Yii 3

[![CI](https://github.com/phasync/swerve-yii/actions/workflows/ci.yaml/badge.svg?branch=main)](https://github.com/phasync/swerve-yii/actions/workflows/ci.yaml)
[![Packagist](https://img.shields.io/packagist/v/phasync/swerve-yii)](https://packagist.org/packages/phasync/swerve-yii)
[![PHP](https://img.shields.io/packagist/dependency-v/phasync/swerve-yii/php)](https://packagist.org/packages/phasync/swerve-yii)
![License](https://img.shields.io/github/license/phasync/swerve-yii)

**Your Yii 3 application, booted once and kept warm.** [swerve](https://github.com/phasync/swerve)
is a PHP application server: long-running workers that serve HTTP/1.1 themselves, stream
request and response bodies, and hold WebSockets and Server-Sent Events. This package lets it
run a Yii 3 application ([yiisoft/app](https://github.com/yiisoft/app)) unchanged. Yii 2 is not
covered.

```bash
composer config minimum-stability alpha && composer config prefer-stable true
composer config minimum-stability alpha   # while swerve is in alpha
composer config prefer-stable true         # everything else stays stable
composer require phasync/swerve-yii
```

```php
<?php // swerve.php, next to composer.json

require __DIR__ . '/src/bootstrap.php'; // yiisoft/app's: the autoloader and .env

return new Swerve\Yii\Handler(__DIR__);
```

```bash
vendor/bin/swerve --http=0.0.0.0:8080 --public=public swerve.php
```

That's the whole setup. `public/index.php` stays as it is, so the same application still runs
under PHP-FPM. `APP_ENV` and `APP_DEBUG` are read from the environment, as the skeleton reads
them; `new Swerve\Yii\Handler(__DIR__, debug: false, environment: 'prod')` sets them instead.

## WebSockets

A Yii action returns `Swerve\Http\WebSocket::from()`'s response; the callback runs on the
connection after the 101. An ordinary GET to the same route is answered 426.

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Http\WebSocket;
use Swerve\Swerve;
use Yiisoft\User\CurrentUser;

final readonly class NewsAction   // Route::get('/news')->action(NewsAction::class)
{
    public function __invoke(ServerRequestInterface $request, CurrentUser $user): ResponseInterface
    {
        $userId = $user->getId();   // now: the callback runs after the request's services are reset

        return WebSocket::from($request, static function (WebSocket $ws) use ($userId) {
            foreach (Swerve::subscribe("user:$userId") as $message) {
                $ws->send($message);
            }
        });
    }
}
```

Any other action, on any worker, pushes to it:

```php
Swerve::publish("user:$userId", json_encode(['text' => 'Your report is ready']));
```

- **Both ways:** `foreach ($ws as $message)` receives until the client closes; `send()`,
  `sendBinary()` and `isBinary()` do the rest. See swerve's
  [realtime guide](https://github.com/phasync/swerve/blob/main/docs/realtime.md).
- **The worker's turn:** the action returns the 101 and its turn ends; the callback runs
  beside the requests that follow. The tests hold 250 WebSockets open on one worker and its
  pages are still answered promptly.
- **The user, the session, route arguments:** read them in the action, before
  `WebSocket::from()`, and pass the values in. `CurrentUser`, `SessionInterface`,
  `CurrentRoute` and the like belong to whichever request the worker serves at that moment:
  read inside the callback, `CurrentUser` gives the guest, or the user of another visitor's
  request in progress (a test shows it).
- **Leaving and stopping:** when the client leaves, with or without a close frame, the callback
  ends, also one that only forwards a subscription. On a shutdown or reload, open sockets are
  closed with 1001 (going away) and the worker exits cleanly.

## What changes

| Yii skeleton, 4 processes | PHP-FPM | swerve | swerve + phasync-ext |
|---|---:|---:|---:|
| JSON route | 984 req/s | 16,686 req/s (17.0×) | 16,788 req/s (17.1×) |
| Home page with session | 824 req/s | 7,483 req/s (9.1×) | 7,275 req/s (8.8×) |

PHP-FPM builds Yii's configuration and container for every request; swerve builds them once per
worker. [Method and raw results](benchmarks/).

## How it runs

- **Once per worker:** the configuration and the container are built, the container's
  `ErrorHandler` is registered, the `bootstrap-web` group runs and `Application::start()`
  dispatches `ApplicationStartup`, as [yiisoft/yii-runner-roadrunner](https://github.com/yiisoft/yii-runner-roadrunner)
  does, with the configuration groups of `public/index.php`'s runner.
- **Per request:** `Application::handle()` runs the middleware stack; an exception escaping it
  goes to `ErrorCatcher`. Then `AfterEmit` is dispatched, `StateResetter` runs the `reset`
  callbacks of the container's definitions (`CurrentRoute`, `RequestProvider`, `Session`,
  `CurrentUser`, `WebView`, ...), and PHP's `session_id()` and `$_SESSION` are cleared.
  yiisoft/session's `Flash` has no reset and keeps the last session id it saw, so its messages
  would not expire ([yiisoft/session#2](https://github.com/yiisoft/session/issues/2)); the
  adapter clears that id too.
- **Concurrency:** one request at a time per worker (`phasync\Util\Synchronized`). Yii keeps a
  request's state in shared services of the container, and its session is PHP's native
  session, one per process: a container per request in flight would still share it. Streamed
  bodies and WebSockets go on after the request's turn, so they don't hold up the worker.
  [Why, and what was tried](docs/concurrency.md).
- **Sessions:** yiisoft/session through PHP's session module, with the save handler the
  application configures (files, Redis, a database).

## Before you deploy

- A request that waits (a database, an HTTP API) keeps its worker's other requests waiting,
  also with phasync-ext. Run at least as many workers as you would run PHP-FPM children.
- A service of your own that keeps request state needs a `reset` callback in its container
  definition, as Yii's own services have: without one, the next request sees that state.
- A streamed body, an SSE producer or a WebSocket callback runs after the reset: take what it
  needs (the user id, route arguments, session values) before returning the response. Read
  inside it, those services show another request's state, another visitor's user included.
- `AfterEmit` is dispatched when the application returns the response, before swerve sends
  it, not after as under PHP-FPM.
- The application's `ErrorHandler` is registered for the whole worker: a PHP warning becomes an
  `ErrorException` (a 500 page in a request, as under PHP-FPM), also in a coroutine that
  outlives its request, such as a stream's producer.
- `exit`, `die` and `dd()` end the worker, and the requests it is serving with it.

## Compatibility

| Yii | PHP | phasync-ext |
|---|---|---|
| 3 (yiisoft/app 1.x) | 8.2 – 8.5 | optional; tested with and without |

## License

MIT. See [the Ennerd philosophy](PHILOSOPHY.md) for why this stack is built to be owned.
