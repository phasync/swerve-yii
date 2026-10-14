# Changelog

## Unreleased

- Requires phasync and swerve `dev-main`, and swerve-psr15 (from its GitHub repository, until it is on
  Packagist): swerve serves an exchange with a `Swerve\RequestHandler` over a `Swerve\ClientRequest`,
  no longer a PSR-15 handler. `Handler` stays a PSR-15 handler, served through swerve-psr15's `Bridge`.
- The `yii` adapter: `vendor/bin/swerve --adapter=yii` runs the skeleton's `src/bootstrap.php` and
  serves the application with no `swerve.php`.
- WebSockets: `Swerve\Psr15\WebSocketResponse::from($request, ...)`; the callback gets swerve's
  `Swerve\WebSocket` (`Swerve\Http\WebSocket` is gone from swerve).
- A stopping worker ends its requests at once (swerve's new stop): a request in flight is answered
  `503` by swerve. An `ApplicationError` listener, which this package adds to the `events-web`
  configuration, notes the `WorkerStoppingException` the `ErrorCatcher` caught, and the handler
  throws it on.

## 0.1.0-alpha1

- First release: Yii 3 (yiisoft/app) on swerve.
