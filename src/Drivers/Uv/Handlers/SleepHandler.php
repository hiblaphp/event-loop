<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Drivers\Uv\Handlers;

use Hibla\EventLoop\Interfaces\SleepHandlerInterface;

final class SleepHandler implements SleepHandlerInterface
{
    /**
     * In the UV driver, sleeping is handled natively by uv_run().
     * it return false here to ensure the PHP-land loop doesn't try to sleep manually.
     */
    public function shouldSleep(bool $hasImmediateWork): bool
    {
        return false;
    }

    public function sleep(int $microseconds): void
    {
        // No-op
    }

    public function calculateOptimalSleep(): int
    {
        return 0;
    }
}
