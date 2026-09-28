<?php

declare(strict_types=1);

namespace App\SwerveTest;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swerve\Http\WebSocket;
use Swerve\Swerve;
use Yiisoft\User\CurrentUser;

/**
 * A WebSocket action that forwards the topic 'news' to the browser, each message addressed to
 * the user who opened it: the user is taken from CurrentUser before WebSocket::from(), since
 * the callback runs after the request's services were reset.
 */
final class NewsSocket
{
    /** The callbacks running in this worker, for the tests. */
    public static int $live = 0;

    public function __invoke(ServerRequestInterface $request, CurrentUser $user): ResponseInterface
    {
        $name = $user->getId() ?? 'guest';

        return WebSocket::from($request, static function (WebSocket $ws) use ($name) {
            ++self::$live;
            try {
                foreach (Swerve::subscribe('news') as $message) {
                    $ws->send("$name: $message");
                }
            } finally {
                --self::$live;
            }
        });
    }
}
