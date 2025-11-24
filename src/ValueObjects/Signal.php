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

    /**
     * Get the signal number
     *
     * @return int The signal number
     */
    public function getSignal(): int
    {
        return $this->signal;
    }

    /**
     * Get the callback function
     *
     * @return callable The callback function
     */
    public function getCallback(): callable
    {
        return $this->callback;
    }

    /**
     * Get the unique identifier
     *
     * @return string The signal listener ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Invoke the signal callback
     *
     * @param int $signal The signal number that was received
     */
    public function invoke(int $signal): void
    {
        ($this->callback)($signal);
    }
}
