<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Fiber;

/**
 * Handles fiber startup operations.
 *
 * This class provides safe methods for starting new fibers
 * with proper error handling and state validation.
 */
final readonly class FiberStartHandler
{
    /**
     * Safely start a new fiber.
     *
     * Attempts to start the given fiber if it hasn't been started yet.
     * Handles any exceptions that may occur during startup.
     *
     * @param  \Fiber<mixed, mixed, mixed, mixed>  $fiber  The fiber to start
     * @return bool True if the fiber was successfully started
     */
    public function startFiber(\Fiber $fiber): bool
    {
        if ($fiber->isTerminated() || $fiber->isStarted()) {
            return false;
        }

        try {
            $fiber->start();

            return true;
        } catch (\Throwable $e) {
            error_log('Fiber start error: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Check if a fiber can be started.
     *
     * A fiber can be started if it's not terminated and hasn't been started yet.
     *
     * @param  \Fiber<mixed, mixed, mixed, mixed>  $fiber  The fiber to check
     * @return bool True if the fiber can be started
     */
    public function canStart(\Fiber $fiber): bool
    {
        return ! $fiber->isTerminated() && ! $fiber->isStarted();
    }
}
