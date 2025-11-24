<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Handlers;

use Hibla\EventLoop\Managers\FiberManager;
use Hibla\EventLoop\Managers\TimerManager;

/**
 * Decides when the event loop should sleep to conserve CPU,
 * based on pending timers and active fibers.
 */
class SleepHandler
{
    /**
     * @var TimerManager Manages scheduled timers and their delays.
     */
    protected TimerManager $timerManager;

    /**
     * @var FiberManager Manages currently active fibers.
     */
    protected FiberManager $fiberManager;

    /**
     * Minimum sleep duration in microseconds to actually perform usleep.
     */
    protected const MIN_SLEEP_THRESHOLD = 50;

    /**
     * Maximum sleep duration in microseconds to avoid long pauses.
     */
    protected const MAX_SLEEP_DURATION = 500;

    /**
     * @param  TimerManager  $timerManager  The timer manager to query next timer delays.
     * @param  FiberManager  $fiberManager  The fiber manager to check active fibers.
     */
    public function __construct(TimerManager $timerManager, FiberManager $fiberManager)
    {
        $this->timerManager = $timerManager;
        $this->fiberManager = $fiberManager;
    }

    /**
     * Determine whether the loop should sleep this iteration.
     *
     * @param  bool  $hasImmediateWork  True if there are immediate tasks (callbacks, I/O, etc.).
     * @return bool True if there is no immediate work and no active fibers.
     */
    public function shouldSleep(bool $hasImmediateWork): bool
    {
        return ! $hasImmediateWork && ! $this->fiberManager->hasActiveFibers();
    }

    /**
     * Calculate the optimal sleep duration in microseconds.
     *
     * - If a next timer exists, sleeps until that timer (capped by MAX_SLEEP_DURATION).
     * - Skips sleeping for very short intervals below MIN_SLEEP_THRESHOLD.
     * - If no timers, uses the minimum threshold.
     *
     * @return int Sleep duration in microseconds.
     */
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

    /**
     * Sleep for the given duration, if it meets the minimum threshold.
     *
     * @param  int  $microseconds  Number of microseconds to sleep.
     */
    public function sleep(int $microseconds): void
    {
        if ($microseconds >= self::MIN_SLEEP_THRESHOLD) {
            usleep($microseconds);
        }
    }
}
