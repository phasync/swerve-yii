# Concurrency

`Swerve\Yii\Handler` serves one request at a time per worker (`phasync\Util\Synchronized`,
[src/Handler.php:79](../src/Handler.php)). A request that waits, for a database or an HTTP API,
holds its worker also with phasync-ext: the next request queues behind it. Size workers as you
would size PHP-FPM children. Streamed bodies and WebSocket callbacks run after the request's
turn and don't hold the worker.

The test `keeps each request's state its own while requests overlap in the worker`
(tests/OneWorkerTest.php) sends 8 requests at once to one worker; each sets its own value in
every service below, waits 50 ms, and reads them back. It passes with the lock. Measured with
Yii 3 (yiisoft/app 1.x), swerve 0.1.0-alpha21, phasync-ext 0.5.0-alpha13; vendor paths below are
under the test application's `vendor/`.

## Why: shared state

**The container's request services.** One container per worker holds `CurrentRoute`
(yiisoft/router/src/CurrentRoute.php:18-30), `RequestProvider`
(yiisoft/request-provider/src/RequestProvider.php:18), `CurrentUser`'s identity
(yiisoft/user/src/CurrentUser.php:36), the translator's locale
(yiisoft/translator/src/Translator.php:39), `WebView`'s title and parameters
(yiisoft/view/src/WebView.php:269), the URL generator's default arguments
(yiisoft/router-fastroute/src/UrlGenerator.php:35), the session's id
(yiisoft/session/src/Session.php:29) and Flash's (yiisoft/session/src/Flash/Flash.php:21). Without
the lock, every request of the test fails or answers with another's values: the router refuses a
second request (`LogicException: Can not set URI since it was already set`,
CurrentRoute.php:106, from Router.php:38); the first request to end runs `StateResetter`
(src/Handler.php:88) under the others, which then find no request (`RequestNotSetException`,
RequestProvider.php:29); a request that gets through reads the last request's value in all eight
fields. Same without phasync-ext, with it, and with it plus `virtualize()`.

**PHP's native session.** yiisoft/session keeps its data in `$_SESSION` (Session.php:64-70) and
asks `session_status()` whether it is open (Session.php:110): one of each per process. With a
container per request (below), the test still fails: 7 of 8 requests read another visitor's
session value, and Flash messages with it. `Session::open()` sees another request's open session
and uses it (Session.php:87-98). Without and with phasync-ext.

Not a reason: the superglobals, output buffers, `header()` and `exit` (Yii works on PSR-7 and
doesn't use them); static properties in the yiisoft packages (caches and an HTML id counter).

## What was tried

**A pool of applications**, one container per request in flight, as swerve-symfony pools its
kernels. Each extra application boots in 6-7 ms and takes about 0.5 MB (the first: 40 ms,
3.3 MB). It removes every container item above; the test then fails only on the session.

**The same, with requests run in `phasync\ext\virtualize()`** (phasync-ext 0.5.0-alpha13): it
gives each request its own session id, status and save handler, but `$_SESSION` stays one global
variable, so the session still leaks (7 of 8). It also gives each request its own error handler:
the application's `ErrorHandler`, registered once per worker, no longer turns warnings into a 500
page (`/warning` answers 200). With one shared container it changes nothing: the container is
not SAPI state.

**A pool of applications with a turn on the session** (branch `concurrent`): the container's
`SessionInterface` is wrapped so a request takes a worker-wide turn when it opens the session and
gives it up when it closes it, clearing `session_id()` and `$_SESSION`. The test passes, and the
whole suite without and with phasync-ext (as on main). One worker, phasync-ext,
`wrk -t2 -c32 -d5s /usleep` (a 10 ms wait): 92 req/s with the lock, 1,364 req/s with the pool.
Requests that use the session still take turns for as long as it is open.
