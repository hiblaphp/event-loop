<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Handlers;

/**
 * Manages the running state of the event loop with enhanced shutdown capabilities.
 *
 * This handler provides state management for the event loop with the ability
 * to force complete shutdown by clearing all pending work to prevent hanging.
 */
final class StateHandler
{
    /**
     * @var bool Whether the event loop is currently running
     */
    private bool $running = true;

    /**
     * @var bool Whether a forced shutdown has been requested
     */
    private bool $forceShutdown = false;

    /**
     * @var float Timestamp when stop() was called
     */
    private float $stopRequestTime = 0.0;

    /**
     * @var float Maximum time to wait for graceful shutdown (seconds)
     */
    private float $gracefulShutdownTimeout = 2.0;

    /**
     * Check if the event loop is currently running.
     *
     * @return bool True if running, false if stopped
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Stop the event loop gracefully.
     *
     * This will cause the main event loop to exit on its next iteration.
     * Records the stop request time for potential forced shutdown.
     */
    public function stop(): void
    {
        if (! $this->running) {
            return;
        }

        $this->running = false;
        $this->stopRequestTime = microtime(true);
    }

    /**
     * Force immediate shutdown of the event loop.
     *
     * This bypasses graceful shutdown and immediately stops the loop.
     * Should be used when graceful shutdown fails or takes too long.
     */
    public function forceStop(): void
    {
        $this->running = false;
        $this->forceShutdown = true;
    }

    /**
     * Check if a forced shutdown has been requested.
     *
     * @return bool True if force shutdown is active
     */
    public function isForcedShutdown(): bool
    {
        return $this->forceShutdown;
    }

    /**
     * Check if the graceful shutdown timeout has been exceeded.
     *
     * @return bool True if timeout exceeded and force shutdown should be triggered
     */
    public function shouldForceShutdown(): bool
    {
        if ($this->running || $this->forceShutdown) {
            return false;
        }

        return (microtime(true) - $this->stopRequestTime) > $this->gracefulShutdownTimeout;
    }

    /**
     * Start the event loop.
     *
     * This resets the running state to true and clears any shutdown flags.
     */
    public function start(): void
    {
        $this->running = true;
        $this->forceShutdown = false;
        $this->stopRequestTime = 0.0;
    }

    /**
     * Set the graceful shutdown timeout.
     *
     * @param  float  $timeout  Timeout in seconds
     */
    public function setGracefulShutdownTimeout(float $timeout): void
    {
        $this->gracefulShutdownTimeout = max(0.1, $timeout);
    }

    /**
     * Get the current graceful shutdown timeout.
     *
     * @return float Timeout in seconds
     */
    public function getGracefulShutdownTimeout(): float
    {
        return $this->gracefulShutdownTimeout;
    }

    /**
     * Get the time elapsed since stop() was called.
     *
     * @return float Time in seconds, or 0.0 if stop hasn't been called
     */
    public function getTimeSinceStopRequest(): float
    {
        if ($this->stopRequestTime === 0.0) {
            return 0.0;
        }

        return microtime(true) - $this->stopRequestTime;
    }

    /**
     * Check if we're currently in a graceful shutdown period.
     *
     * @return bool True if stop() was called but timeout hasn't been reached
     */
    public function isInGracefulShutdown(): bool
    {
        return ! $this->running &&
               ! $this->forceShutdown &&
               $this->stopRequestTime > 0.0;
    }
}
