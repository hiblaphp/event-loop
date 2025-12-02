<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Handlers;

use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\Interfaces\SleepHandlerInterface;
use Hibla\EventLoop\Interfaces\TimerManagerInterface;

final class SleepHandler implements SleepHandlerInterface
{
    protected const int MIN_SLEEP_THRESHOLD = 50;

    protected const int MAX_SLEEP_DURATION = 500;

    public function __construct(protected TimerManagerInterface $timerManager, protected FiberManagerInterface $fiberManager)
    {
    }

    public function shouldSleep(bool $hasImmediateWork): bool
    {
        return ! $hasImmediateWork && ! $this->fiberManager->hasActiveFibers();
    }

    public function calculateOptimalSleep(): int
    {
        $nextTimerSeconds = $this->timerManager->getNextTimerDelay();

        if ($nextTimerSeconds !== null) {
            $sleepMicros = (int) ($nextTimerSeconds * 1_000_000);

            // Skip very short sleeps to avoid system call overhead
            if ($sleepMicros < self::MIN_SLEEP_THRESHOLD) {
                return 0;
            }

            return min(self::MAX_SLEEP_DURATION, $sleepMicros);
        }

        // No timers scheduled: use minimum threshold
        return self::MIN_SLEEP_THRESHOLD;
    }

    public function sleep(int $microseconds): void
    {
        if ($microseconds >= self::MIN_SLEEP_THRESHOLD) {
            usleep($microseconds);
        }
    }
}
