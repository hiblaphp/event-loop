<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Fiber;

final readonly class FiberResumeHandler
{
    /**
     * @param  \Fiber<mixed, mixed, mixed, mixed>  $fiber
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
     * @param  \Fiber<mixed, mixed, mixed, mixed>  $fiber  The fiber to check
     * @return bool True if the fiber can be resumed
     */
    public function canResume(\Fiber $fiber): bool
    {
        return ! $fiber->isTerminated() && $fiber->isSuspended();
    }
}
