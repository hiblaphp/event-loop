<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Handlers;

final class ActivityHandler
{
    private float $lastActivity = 0.0;

    private int $idleThreshold = 5;

    private int $activityCounter = 0;

    private float $avgActivityInterval = 0.0;

    public function __construct()
    {
        $this->lastActivity = microtime(true);
    }

    public function updateLastActivity(): void
    {
        $now = microtime(true);

        // Calculate average activity interval for adaptive behavior
        if ($this->activityCounter > 0) {
            $interval = $now - $this->lastActivity;
            $this->avgActivityInterval = $this->avgActivityInterval * 0.9 + $interval * 0.1;
        }

        $this->lastActivity = $now;
        $this->activityCounter++;
    }

    public function isIdle(): bool
    {
        $idleTime = microtime(true) - $this->lastActivity;

        // Adaptive idle threshold based on activity patterns
        $adaptiveThreshold = $this->activityCounter > 100
            ? max(1, $this->avgActivityInterval * 10)
            : $this->idleThreshold;

        return $idleTime > $adaptiveThreshold;
    }

    public function getLastActivity(): float
    {
        return $this->lastActivity;
    }

    /**
     * @return array{counter:int, avg_interval:float, idle_time:float}
     */
    public function getActivityStats(): array
    {
        return [
            'counter' => $this->activityCounter,
            'avg_interval' => $this->avgActivityInterval,
            'idle_time' => microtime(true) - $this->lastActivity,
        ];
    }
}
