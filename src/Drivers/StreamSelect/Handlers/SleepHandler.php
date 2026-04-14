<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Drivers\StreamSelect\Handlers;

use Hibla\EventLoop\Interfaces\CurlRequestManagerInterface;
use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\Interfaces\SleepHandlerInterface;
use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\Interfaces\TimerManagerInterface;

final class SleepHandler implements SleepHandlerInterface
{
    public function __construct(
        private TimerManagerInterface $timerManager,
        private FiberManagerInterface $fiberManager,
        private CurlRequestManagerInterface $curlRequestManager,
        private StreamManagerInterface $streamManager,
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

        if ($this->streamManager->hasWatchers()) {
            return false;
        }

        if ($this->curlRequestManager->hasRequests()) {
            return false;
        }

        if ($this->fiberManager->hasActiveFibers()) {
            return false;
        }

        return true;
    }

    public function calculateOptimalSleep(): int
    {
        $nextTimerDelay = $this->timerManager->getNextTimerDelay();

        if ($nextTimerDelay === null) {
            // No timers pending. Sleep for 1 second.
            // Waking up once per second keeps CPU usage at 0.00% while
            // periodically polling just in case of edge-case state changes.
            return 1_000_000_000;
        }

        $delayNs = (int) ($nextTimerDelay * 1_000_000_000);

        // Sleep exactly until the timer fires, clamped at 100 microseconds
        // minimum to prevent a busy-spin if a timer is somehow 0.00001s away.
        return max($delayNs, 100_000);
    }

    public function sleep(int $nanoseconds): void
    {
        if ($nanoseconds <= 0) {
            return;
        }

        $seconds = intdiv($nanoseconds, 1_000_000_000);
        $remainingNs = $nanoseconds % 1_000_000_000;

        // Sleep using time_nanosleep.
        // If it is interrupted by an OS signal, it returns an array.
        //  Intentionally do NOT retry here. Returning immediately allows the
        // event loop to tick and process the pending signal natively.
        @time_nanosleep($seconds, $remainingNs);
    }
}
