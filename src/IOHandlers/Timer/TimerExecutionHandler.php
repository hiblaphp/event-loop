<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Timer;

use Hibla\EventLoop\ValueObjects\Timer;
use Throwable;

/**
 * Handles timer execution and readiness detection for both regular and periodic timers.
 *
 * This class manages the execution of timers that are ready to run,
 * filtering timers by readiness, and executing their callbacks with
 * appropriate lifecycle management.
 */
final readonly class TimerExecutionHandler
{
    /**
     * Executes all timers that are ready at the given current time.
     *
     * This method modifies the timers array by reference, removing timers
     * after they have been executed.
     *
     * @param  array<string, Timer>  &$timers  A map of timers, keyed by their string ID.
     *                                         This array is modified by this method.
     * @param  float  $currentTime  The current microtime timestamp.
     * @return bool True if at least one timer was executed, false otherwise.
     */
    public function executeReadyTimers(array &$timers, float $currentTime): bool
    {
        $processed = false;

        foreach ($timers as $timerId => $timer) {
            if ($timer->isReady($currentTime)) {
                $this->executeTimer($timer);

                unset($timers[$timerId]);
                $processed = true;
            }
        }

        return $processed;
    }

    /**
     * Get all timers that are ready to execute from a map of timers.
     *
     * Filters the timers map to return only those that are
     * ready to run at the current time.
     *
     * @param  array<string, Timer>  $timers  Map of timers to check.
     * @param  float  $currentTime  Current timestamp for readiness check.
     * @return array<string, Timer> Map of ready timers.
     */
    public function getReadyTimers(array $timers, float $currentTime): array
    {
        return array_filter(
            $timers,
            fn (Timer $timer): bool => $timer->isReady($currentTime)
        );
    }

    /**
     * Safely executes the callback associated with the given timer.
     *
     * @param  Timer  $timer  The timer to execute.
     */
    public function executeTimer(Timer $timer): void
    {
        try {
            $timer->execute();
        } catch (Throwable $e) {
            error_log('Timer callback error for timer '.$timer->getId().': '.$e->getMessage());
        }
    }
}
