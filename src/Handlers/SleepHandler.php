<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Handlers;

use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\Interfaces\FileManagerInterface;
use Hibla\EventLoop\Interfaces\HttpRequestManagerInterface;
use Hibla\EventLoop\Interfaces\SleepHandlerInterface;
use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\Interfaces\TimerManagerInterface;

final class SleepHandler implements SleepHandlerInterface
{
    private const int WINDOWS_MAX_SLEEP_NS = 1_000_000;
    private const int UNIX_MAX_SLEEP_NS = 10_000_000;
    private const int MAX_SLEEP_RETRIES = 10;
    private const bool IS_WINDOWS = PHP_OS_FAMILY === 'Windows';

    public function __construct(
        private TimerManagerInterface $timerManager,
        private FiberManagerInterface $fiberManager,
        private HttpRequestManagerInterface $httpRequestManager,
        private StreamManagerInterface $streamManager,
        private FileManagerInterface $fileManager,
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

        $hasWaitingIO = $this->httpRequestManager->hasRequests()
            || $this->streamManager->hasWatchers()
            || $this->fileManager->hasWork();

        if ($hasWaitingIO) {
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
        $maxSleepNs = self::IS_WINDOWS ? self::WINDOWS_MAX_SLEEP_NS : self::UNIX_MAX_SLEEP_NS;

        if ($nextTimerDelay === null) {
            return $maxSleepNs;
        }

        $delayNs = (int)($nextTimerDelay * 1_000_000_000);

        $bufferDelayNs = (int)($delayNs * 0.9);

        return min(
            max($bufferDelayNs, 100_000),
            $maxSleepNs
        );
    }

    public function sleep(int $nanoseconds): void
    {
        if ($nanoseconds <= 0) {
            return;
        }

        $remaining = $nanoseconds;
        $retries = 0;

        while ($remaining > 0 && $retries < self::MAX_SLEEP_RETRIES) {
            $seconds = intdiv($remaining, 1_000_000_000);
            $remainingNs = $remaining % 1_000_000_000;

            $result = time_nanosleep($seconds, $remainingNs);

            if ($result === true) {
                return;
            }

            // Just in case, if time_nanosleep returns an array, we update the remaining time
            if (\is_array($result)) {
                $remaining = $result['seconds'] * 1_000_000_000 + $result['nanoseconds'];
                $retries++;

                continue;
            }

            return;
        }
    }
}
