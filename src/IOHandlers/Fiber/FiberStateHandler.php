<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Fiber;

/**
 * Handles fiber state management and filtering operations.
 *
 * This class provides utilities for filtering fibers by state,
 * checking for active fibers, and getting human-readable state information.
 */
final readonly class FiberStateHandler
{
    /**
     * Filter an array of fibers to return only active (non-terminated) ones.
     *
     * @param  \Fiber<mixed, mixed, mixed, mixed>[]  $fibers  Array of fibers to filter
     * @return \Fiber<mixed, mixed, mixed, mixed>[] Array containing only active fibers
     */
    public function filterActiveFibers(array $fibers): array
    {
        return array_filter(
            $fibers,
            fn (\Fiber $fiber): bool => ! $fiber->isTerminated()
        );
    }

    /**
     * Filter an array of fibers to return only suspended ones.
     *
     * Returns fibers that are suspended but not terminated.
     *
     * @param  \Fiber<mixed, mixed, mixed, mixed>[]  $fibers  Array of fibers to filter
     * @return \Fiber<mixed, mixed, mixed, mixed>[] Array containing only suspended fibers
     */
    public function filterSuspendedFibers(array $fibers): array
    {
        return array_filter(
            $fibers,
            fn (\Fiber $fiber): bool => $fiber->isSuspended() && ! $fiber->isTerminated()
        );
    }

    /**
     * Check if there are any active fibers in the given array.
     *
     * An active fiber is one that is not terminated.
     *
     * @param  \Fiber<mixed, mixed, mixed, mixed>[]  $fibers  Array of fibers to check
     * @return bool True if at least one fiber is active
     */
    public function hasActiveFibers(array $fibers): bool
    {
        foreach ($fibers as $fiber) {
            if (! $fiber->isTerminated()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get a human-readable string representation of a fiber's state.
     *
     * @param  \Fiber<mixed, mixed, mixed, mixed>  $fiber  The fiber to get state for
     * @return string One of: 'terminated', 'suspended', 'running', 'not_started'
     */
    public function getFiberState(\Fiber $fiber): string
    {
        if ($fiber->isTerminated()) {
            return 'terminated';
        }

        if ($fiber->isSuspended()) {
            return 'suspended';
        }

        if ($fiber->isStarted()) {
            return 'running';
        }

        return 'not_started';
    }
}
