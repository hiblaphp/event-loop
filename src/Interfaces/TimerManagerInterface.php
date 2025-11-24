<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Interfaces;

/**
 * Interface for managing timer lifecycle.
 */
interface TimerManagerInterface
{
    /**
     * Adds a new one-time timer.
     *
     * @param  float  $delay  Delay in seconds
     * @param  callable  $callback  The timer callback
     * @return string The timer ID
     */
    public function addTimer(float $delay, callable $callback): string;

    /**
     * Adds a new periodic timer.
     *
     * @param  float  $interval  Interval in seconds
     * @param  callable  $callback  The timer callback
     * @param  int|null  $maxExecutions  Maximum executions (null for infinite)
     * @return string The timer ID
     */
    public function addPeriodicTimer(float $interval, callable $callback, ?int $maxExecutions = null): string;

    /**
     * Cancels a timer by ID.
     *
     * @param  string  $timerId  The timer ID to cancel
     * @return bool True if cancelled, false if not found
     */
    public function cancelTimer(string $timerId): bool;

    /**
     * Processes all timers and executes ready ones.
     *
     * @return bool True if any timer was executed
     */
    public function processTimers(): bool;

    /**
     * Checks if there are any pending timers.
     *
     * @return bool True if there are timers
     */
    public function hasTimers(): bool;

    /**
     * Gets the delay until the next timer fires.
     *
     * @return float|null Delay in seconds, or null if no timers
     */
    public function getNextTimerDelay(): ?float;

    /**
     * Clears all pending timers.
     */
    public function clearAllTimers(): void;
}
