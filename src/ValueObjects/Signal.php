<?php

declare(strict_types=1);

namespace Hibla\EventLoop\ValueObjects;

/**
 * Represents a signal listener in the event loop.
 */
final class Signal
{
    /**
     * @param int $signal The signal number (e.g., SIGINT, SIGTERM)
     * @param callable(int): void $callback Callback to invoke when signal is received
     * @param string $id Unique identifier for this signal listener
     */
    public function __construct(
        private readonly int $signal,
        private readonly mixed $callback,
        private readonly string $id
    ) {
    }

    public function getSignal(): int
    {
        return $this->signal;
    }

    public function getCallback(): callable
    {
        return $this->callback;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function invoke(int $signal): void
    {
        ($this->callback)($signal);
    }
}
