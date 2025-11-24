<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Handlers;

/**
 * Handles tracking of activity timestamps and computes idle status
 * based on adaptive thresholds.
 */
final class ActivityHandler
{
    /**
     * The timestamp of the last recorded activity.
     */
    private float $lastActivity = 0.0;

    /**
     * The default idle threshold in seconds when activityCounter ≤ 100.
     */
    private int $idleThreshold = 5;

    /**
     * Counts how many times activity has been updated.
     */
    private int $activityCounter = 0;

    /**
     * Exponential moving average of intervals between activities.
     */
    private float $avgActivityInterval = 0.0;

    /**
     * Initialize the handler by setting lastActivity to the current time.
     */
    public function __construct()
    {
        $this->lastActivity = microtime(true);
    }

    /**
     * Record a new activity timestamp, updating the moving average interval.
     */
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

    /**
     * Determine if the handler has been idle longer than the threshold.
     *
     * If more than 100 updates have occurred, threshold is adaptive:
     * max(1, avg_interval * 10). Otherwise, uses $idleThreshold.
     *
     * @return bool True if idle, false otherwise.
     */
    public function isIdle(): bool
    {
        $idleTime = microtime(true) - $this->lastActivity;

        // Adaptive idle threshold based on activity patterns
        $adaptiveThreshold = $this->activityCounter > 100
            ? max(1, $this->avgActivityInterval * 10)
            : $this->idleThreshold;

        return $idleTime > $adaptiveThreshold;
    }

    /**
     * Get the timestamp of the last recorded activity.
     *
     * @return float UNIX timestamp (in seconds with microseconds).
     */
    public function getLastActivity(): float
    {
        return $this->lastActivity;
    }

    /**
     * Get statistics about activity.
     *
     * @return array{counter:int, avg_interval:float, idle_time:float}
     *                                                                 - counter: total number of activity updates
     *                                                                 - avg_interval: exponential moving average of intervals
     *                                                                 - idle_time: seconds since last activity
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
