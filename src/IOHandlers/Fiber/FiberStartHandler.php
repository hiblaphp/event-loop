<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Fiber;

final readonly class FiberStartHandler
{
    /**
     * @param  \Fiber<mixed, mixed, mixed, mixed>  $fiber
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
     * @param  \Fiber<mixed, mixed, mixed, mixed>  $fiber
     * @return bool True if the fiber can be started
     */
    public function canStart(\Fiber $fiber): bool
    {
        return ! $fiber->isTerminated() && ! $fiber->isStarted();
    }
}
