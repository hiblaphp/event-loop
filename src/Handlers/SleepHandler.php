<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Handlers;

use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\Interfaces\SleepHandlerInterface;
use Hibla\EventLoop\Interfaces\TimerManagerInterface;

final class SleepHandler implements SleepHandlerInterface
{
    private const int WINDOWS_MAX_SLEEP_US = 1000;
    private const int UNIX_MAX_SLEEP_US = 10000;
    private const bool IS_WINDOWS = PHP_OS_FAMILY === 'Windows';

    public function __construct(
        private TimerManagerInterface $timerManager,
        private FiberManagerInterface $fiberManager
    ) {
    }

    public function shouldSleep(bool $hasImmediateWork): bool
    {
        if ($hasImmediateWork) {
            return false;
        }

        if ($this->timerManager->hasReadyTimers()) {
            return false;
        }

        if ($this->fiberManager->hasActiveFibers()) {
            return true;
        }

        return true;
    }

    public function calculateOptimalSleep(): int
    {
        $nextTimerDelay = $this->timerManager->getNextTimerDelay();

        if ($nextTimerDelay === null) {
            return self::IS_WINDOWS ? self::WINDOWS_MAX_SLEEP_US : self::UNIX_MAX_SLEEP_US;
        }

        $delayUs = (int)($nextTimerDelay * 1_000_000);

        if (self::IS_WINDOWS) {
            return min($delayUs, self::WINDOWS_MAX_SLEEP_US);
        }

        return min($delayUs, self::UNIX_MAX_SLEEP_US);
    }

    public function sleep(int $microseconds): void
    {
        if ($microseconds <= 0) {
            return;
        }

        if (self::IS_WINDOWS) {
            usleep(min($microseconds, self::WINDOWS_MAX_SLEEP_US));

            return;
        }

        usleep($microseconds);
    }
}
