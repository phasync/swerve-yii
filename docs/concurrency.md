# Concurrency

`Swerve\Yii\Handler::handle()` runs one request at a time per worker: the whole request,
including any time it spends waiting on a database or an HTTP API, runs inside
`phasync\Util\Synchronized::run($this, ...)` ([src/Handler.php](../src/Handler.php)), a mutex
held until the closure returns. A second request arriving at the same worker queues behind it,
with or without the phasync extension.

## Why

Yii builds one container per worker, and that container holds a request's state in shared
services: `CurrentRoute`, `RequestProvider`, `CurrentUser`'s identity, the translator's locale,
`WebView`'s title and parameters, the URL generator's default arguments, and the session
(`Session` and `Flash`). Two requests served by the same container at the same time would read
and write each other's values in all of them; `StateResetter` resets these services between
requests, not between requests that overlap, because there is only one container per worker.

PHP's session is also one `$_SESSION` and one `session_id()` per process, independent of the
container: two requests open on the same worker would read and write the same session data
regardless of which container built them.

`tests/OneWorkerTest.php`'s test `keeps each request's state its own while requests overlap in
the worker` sends 8 requests to one worker at once; each sets its own value in `CurrentRoute`,
`RequestProvider`, the session, `Flash`, `CurrentUser`, the translator, `WebView` and the URL
generator, and reads it back. It passes because `Synchronized::run()` serializes the requests.

Not a reason for the lock: the superglobals, output buffers, `header()` and `exit` (Yii works on
PSR-7 and doesn't use them); static properties in the yiisoft packages (caches and an HTML id
counter).

## In practice

A request that waits on I/O holds its worker for the whole wait: the adapter does not interleave
another request's work in that time. Capacity scales with the number of workers (`--workers`),
not with how many requests one worker can run at once; size workers for a Yii application at
least as you would size PHP-FPM children, and more for an application that spends most of a
request waiting, as [swerve's production guide](https://github.com/phasync/swerve/blob/main/docs/production.md)
describes for other adapters.

Work that doesn't need the response can run outside the lock: a `WebSocketResponse` or
`EventStreamResponse` callback (see [WebSockets](../README.md#websockets)) starts after
`Handler::handle()` returns, alongside the requests that follow, not before it. Read whatever
the callback needs from the container's services — the user id, route arguments, session
values — before returning the response; by the time the callback runs, those services belong to
another request.

Dropping the lock would mean making the state above per-request instead of shared; this adapter
doesn't do that today. swerve already runs each request in its own
[`\Scope`](https://wiki.php.net/rfc/scope) (swerve's `src/Http/HttpConnection.php`), and
`Scope::getCurrent()` identifies that request across its fibers. Two routes build on it: (1) key
a value by the `Scope`, in a `WeakMap<\Scope, …>`, instead of in a service's own property — how
`CurrentRoute`, `RequestProvider`, `CurrentUser`, the translator, `WebView`, the URL generator
and `Flash` could become per-request without a container per request. With `fiber-interop/ext-scope`
(bundled in the phasync extension) or its userland polyfill `fiber-interop/scope` loaded,
`$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_REQUEST`, the default timezone and the intl locale
already follow the scope this way, built in. (2) `phasync::onScopeChange(fn (Scope $new, ?Scope
$old) => ...)`, a hook that runs before a coroutine whose scope changed, to save the outgoing
scope's value of a global and load the incoming scope's — phasync's own example does this for a
locale. `$_SESSION` and `session_id()` are plain process globals that `ext-scope` leaves alone,
so this hook, not the `WeakMap` route, is what would make PHP's session per-request.
