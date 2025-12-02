<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Fiber;

final readonly class FiberStateHandler
{
    /**
     * @param  \Fiber<mixed, mixed, mixed, mixed>[]  $fibers
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
     * @param  \Fiber<mixed, mixed, mixed, mixed>[]  $fibers
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
     * @param  \Fiber<mixed, mixed, mixed, mixed>[]  $fibers
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
}
