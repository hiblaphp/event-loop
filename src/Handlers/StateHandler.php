<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Handlers;

final class StateHandler
{
    private bool $running = true;

    private bool $forceShutdown = false;

    private float $stopRequestTime = 0.0;

    private float $gracefulShutdownTimeout = 2.0;

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
        $this->stopRequestTime = microtime(true);
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

        return microtime(true) - $this->stopRequestTime > $this->gracefulShutdownTimeout;
    }

    public function start(): void
    {
        $this->running = true;
        $this->forceShutdown = false;
        $this->stopRequestTime = 0.0;
    }

    public function setGracefulShutdownTimeout(float $timeout): void
    {
        $this->gracefulShutdownTimeout = max(0.1, $timeout);
    }

    public function getGracefulShutdownTimeout(): float
    {
        return $this->gracefulShutdownTimeout;
    }

    public function getTimeSinceStopRequest(): float
    {
        if ($this->stopRequestTime === 0.0) {
            return 0.0;
        }

        return microtime(true) - $this->stopRequestTime;
    }

    public function isInGracefulShutdown(): bool
    {
        return ! $this->running &&
               ! $this->forceShutdown &&
               $this->stopRequestTime > 0.0;
    }
}
