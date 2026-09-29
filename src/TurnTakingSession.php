<?php

namespace Swerve\Yii;

use phasync\Util\Pool;
use Yiisoft\Session\SessionInterface;

/**
 * The application's session, taking turns with the other requests of the worker: PHP's native
 * session (session_id(), session_status(), $_SESSION) is one per process, so one request at a
 * time has it open. A request waits for its turn when it opens the session and gives it up when it
 * closes it; requests that don't use the session never wait.
 *
 * Each request uses a fresh copy of the application's session service, made from the one the
 * container built, so no session id stays behind for the next visitor.
 *
 * @internal installed by Handler, around the container's SessionInterface
 */
final class TurnTakingSession implements SessionInterface
{
    /** Set by yiisoft/session's reset callback, which is bound to this object; not used. */
    private ?string $sessionId = null;

    private ?SessionInterface $session = null;
    private ?object $turn              = null;

    /** @param Pool<object> $turns the worker's one turn */
    public function __construct(private readonly SessionInterface $pristine, private readonly Pool $turns)
    {
    }

    /** The request ended: close the session if it is still open, and start the next with a fresh copy. */
    public function end(): void
    {
        $this->close();
        $this->session = null;
    }

    public function get(string $key, $default = null)
    {
        return $this->enter()->get($key, $default);
    }

    public function set(string $key, $value): void
    {
        $this->enter()->set($key, $value);
    }

    public function close(): void
    {
        if (null !== $this->turn) {
            try {
                $this->session->close();
            } finally {
                $this->release();
            }
        }
    }

    public function open(): void
    {
        $this->enter();
    }

    /** The session, open, once it is this request's turn. */
    private function enter(): SessionInterface
    {
        if (null === $this->turn) {
            $this->turn = $this->turns->borrow();
        }
        $session = $this->session();
        $session->open();

        return $session;
    }

    public function isActive(): bool
    {
        return null !== $this->turn && $this->session()->isActive();
    }

    public function getId(): ?string
    {
        return $this->session()->getId();
    }

    public function setId(string $sessionId): void
    {
        $this->session()->setId($sessionId);
    }

    public function regenerateId(): void
    {
        $this->enter()->regenerateId();
    }

    public function discard(): void
    {
        if (null !== $this->turn) {
            try {
                $this->session->discard();
            } finally {
                $this->release();
            }
        }
    }

    public function getName(): string
    {
        return $this->session()->getName();
    }

    public function all(): array
    {
        return $this->enter()->all();
    }

    public function remove(string $key): void
    {
        $this->enter()->remove($key);
    }

    public function has(string $key): bool
    {
        return $this->enter()->has($key);
    }

    public function pull(string $key, $default = null)
    {
        return $this->enter()->pull($key, $default);
    }

    public function clear(): void
    {
        $this->enter()->clear();
    }

    public function destroy(): void
    {
        $this->enter()->destroy();
    }

    public function getCookieParameters(): array
    {
        return $this->session()->getCookieParameters();
    }

    private function session(): SessionInterface
    {
        return $this->session ??= clone $this->pristine;
    }

    /** PHP keeps the last session id and $_SESSION: cleared, or the next opener would get them. */
    private function release(): void
    {
        \session_id('');
        $_SESSION   = [];
        $turn       = $this->turn;
        $this->turn = null;
        $this->turns->release($turn);
    }
}
