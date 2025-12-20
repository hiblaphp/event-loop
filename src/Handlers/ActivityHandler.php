<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Handlers;

final class ActivityHandler
{
    private int $lastActivity = 0;

    private int $idleThresholdNs = 5_000_000_000;

    private int $activityCounter = 0;

    private int $avgActivityIntervalNs = 0;

    public function __construct()
    {
        $this->lastActivity = hrtime(true);
    }

    public function updateLastActivity(): void
    {
        $now = hrtime(true);

        // Calculate average activity interval for adaptive behavior
        if ($this->activityCounter > 0) {
            $interval = $now - $this->lastActivity;
            $this->avgActivityIntervalNs = (int)(
                $this->avgActivityIntervalNs * 0.9 + $interval * 0.1
            );
        }

        $this->lastActivity = $now;
        $this->activityCounter++;
    }

    public function isIdle(): bool
    {
        $idleTimeNs = hrtime(true) - $this->lastActivity;

        // Adaptive idle threshold based on activity patterns
        $adaptiveThreshold = $this->activityCounter > 100
            ? max(1_000_000_000, $this->avgActivityIntervalNs * 10)
            : $this->idleThresholdNs;

        return $idleTimeNs > $adaptiveThreshold;
    }

    public function getLastActivity(): int
    {
        return $this->lastActivity;
    }

    /**
     * @return array{counter:int, avg_interval_ns:int, idle_time_ns:int}
     */
    public function getActivityStats(): array
    {
        return [
            'counter' => $this->activityCounter,
            'avg_interval_ns' => $this->avgActivityIntervalNs,
            'idle_time_ns' => hrtime(true) - $this->lastActivity,
        ];
    }
}
