<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Handlers;

final class StateHandler
{
    private bool $running = true;

    private bool $forceShutdown = false;

    private int $stopRequestTimeNs = 0;

    private int $gracefulShutdownTimeoutNs = 2_000_000_000;

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function stop(): void
    {
        if (! $this->running) {
            return;
        }

        $this->running = false;
        $this->stopRequestTimeNs = hrtime(true);
    }

    public function forceStop(): void
    {
        $this->running = false;
        $this->forceShutdown = true;
    }

    public function isForcedShutdown(): bool
    {
        return $this->forceShutdown;
    }

    public function shouldForceShutdown(): bool
    {
        if ($this->running || $this->forceShutdown) {
            return false;
        }

        return hrtime(true) - $this->stopRequestTimeNs > $this->gracefulShutdownTimeoutNs;
    }

    public function start(): void
    {
        $this->running = true;
        $this->forceShutdown = false;
        $this->stopRequestTimeNs = 0;
    }

    public function setGracefulShutdownTimeout(float $timeout): void
    {
        $this->gracefulShutdownTimeoutNs = (int)(max(0.1, $timeout) * 1_000_000_000);
    }

    public function getGracefulShutdownTimeout(): float
    {
        return $this->gracefulShutdownTimeoutNs / 1_000_000_000;
    }

    public function getTimeSinceStopRequest(): float
    {
        if ($this->stopRequestTimeNs === 0) {
            return 0.0;
        }

        return (hrtime(true) - $this->stopRequestTimeNs) / 1_000_000_000;
    }

    public function isInGracefulShutdown(): bool
    {
        return ! $this->running &&
               ! $this->forceShutdown &&
               $this->stopRequestTimeNs > 0;
    }
}