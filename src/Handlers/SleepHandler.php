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
    private const int WINDOWS_MAX_SLEEP_US = 1000;
    private const int UNIX_MAX_SLEEP_US = 10000;
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
        $maxSleep = self::IS_WINDOWS ? self::WINDOWS_MAX_SLEEP_US : self::UNIX_MAX_SLEEP_US;

        if ($nextTimerDelay === null) {
            return $maxSleep;
        }

        $delayUs = (int)($nextTimerDelay * 1_000_000);

        $bufferDelayUs = (int)($delayUs * 0.9);


        return min(
            max($bufferDelayUs, 100),
            $maxSleep
        );
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