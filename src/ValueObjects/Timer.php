<?php

declare(strict_types=1);

namespace Hibla\EventLoop\ValueObjects;

/**
 * Value object representing a scheduled timer in the async event loop.
 *
 * This class encapsulates all the information needed to manage timed
 * operations within the event loop system. It tracks when the timer
 * should execute, provides methods to check readiness, and handles
 * callback execution when the scheduled time arrives.
 *
 * Timers are essential for implementing delays, timeouts, intervals,
 * and other time-based operations in the async system without blocking
 * the event loop or interfering with concurrent operations.
 */
final class Timer
{
    /**
     * @var string Unique identifier for this timer instance
     */
    private string $id;

    /**
     * @var callable Callback function to execute when timer fires
     */
    private $callback;

    /**
     * @var float Absolute timestamp when this timer should execute
     */
    private float $executeAt;

    /**
     * Create a new timer with specified delay and callback.
     *
     * Calculates the absolute execution time based on the current timestamp
     * and the specified delay. Generates a unique ID for timer tracking
     * and management within the event loop system.
     *
     * @param  float  $delay  Number of seconds to delay before execution
     * @param  callable  $callback  Function to call when timer fires
     */
    public function __construct(float $delay, callable $callback)
    {
        $this->id = uniqid('timer_', true);
        $this->callback = $callback;
        $this->executeAt = microtime(true) + $delay;
    }

    /**
     * Get the unique identifier for this timer.
     *
     * Returns the unique ID assigned to this timer instance. This ID
     * is used by the event loop for timer tracking, cancellation,
     * and management operations.
     *
     * @return string The unique timer identifier
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get the callback function for this timer.
     *
     * Returns the callback that should be executed when the timer
     * reaches its scheduled execution time. The callback handles
     * the actual work and any promise resolution that needs to occur.
     *
     * @return callable The timer's callback function
     */
    public function getCallback(): callable
    {
        return $this->callback;
    }

    /**
     * Get the absolute timestamp when this timer should execute.
     *
     * Returns the exact microtime timestamp when this timer is
     * scheduled to fire. Used by the event loop to determine
     * timer readiness and execution order.
     *
     * @return float The execution timestamp in microtime format
     */
    public function getExecuteAt(): float
    {
        return $this->executeAt;
    }

    /**
     * Check if the timer is ready to execute at the given time.
     *
     * Compares the current time with the scheduled execution time
     * to determine if the timer should fire. Returns true when
     * the current time meets or exceeds the scheduled time.
     *
     * @param  float  $currentTime  The current timestamp to check against
     * @return bool True if the timer is ready to execute, false otherwise
     */
    public function isReady(float $currentTime): bool
    {
        return $currentTime >= $this->executeAt;
    }

    /**
     * Execute the timer's callback function.
     *
     * Called by the event loop when the timer reaches its scheduled
     * execution time. Invokes the callback function to perform the
     * scheduled operation, which may include promise resolution,
     * fiber resumption, or other async operations.
     */
    public function execute(): void
    {
        ($this->callback)();
    }
}
