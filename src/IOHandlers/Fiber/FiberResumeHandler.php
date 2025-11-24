<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Fiber;

/**
 * Handles fiber resumption operations.
 *
 * This class provides safe methods for resuming suspended fibers
 * with proper error handling and state validation.
 */
final readonly class FiberResumeHandler
{
    /**
     * Safely resume a suspended fiber.
     *
     * Attempts to resume the given fiber if it's in a suspended state.
     * Handles any exceptions that may occur during resumption.
     *
     * @param  \Fiber<mixed, mixed, mixed, mixed>  $fiber  The fiber to resume
     * @return bool True if the fiber was successfully resumed
     */
    public function resumeFiber(\Fiber $fiber): bool
    {
        if ($fiber->isTerminated() || ! $fiber->isSuspended()) {
            return false;
        }

        try {
            $fiber->resume();

            return true;
        } catch (\Throwable $e) {
            error_log('Fiber resume error: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Check if a fiber can be resumed.
     *
     * A fiber can be resumed if it's not terminated and is currently suspended.
     *
     * @param  \Fiber<mixed, mixed, mixed, mixed>  $fiber  The fiber to check
     * @return bool True if the fiber can be resumed
     */
    public function canResume(\Fiber $fiber): bool
    {
        return ! $fiber->isTerminated() && $fiber->isSuspended();
    }
}
