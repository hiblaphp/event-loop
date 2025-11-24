<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use Hibla\EventLoop\Interfaces\TimerManagerInterface;
use Hibla\EventLoop\IOHandlers\Timer\TimerExecutionHandler;
use Hibla\EventLoop\IOHandlers\Timer\TimerScheduleHandler;
use Hibla\EventLoop\ValueObjects\PeriodicTimer;
use Hibla\EventLoop\ValueObjects\Timer;

/**
 * Manages the lifecycle of all timers for the event loop.
 *
 * This class handles the creation, cancellation, and processing of timers,
 * determining when they are ready to be executed.
 */
final class TimerManager implements TimerManagerInterface
{
    /**
     * A map of active timers, keyed by their unique string ID.
     *
     * @var array<string, Timer|PeriodicTimer>
     */
    private array $timers = [];

    private readonly TimerExecutionHandler $executionHandler;
    private readonly TimerScheduleHandler $scheduleHandler;

    public function __construct()
    {
        $this->executionHandler = new TimerExecutionHandler();
        $this->scheduleHandler = new TimerScheduleHandler();
    }

    /**
     * Adds a new timer to the manager.
     *
     * @param  float  $delay  The delay in seconds before the timer should fire.
     * @param  callable  $callback  The callback to execute when the timer fires.
     * @return string The unique ID of the created timer, which can be used for cancellation.
     */
    public function addTimer(float $delay, callable $callback): string
    {
        $timer = $this->scheduleHandler->createTimer($delay, $callback);
        $this->timers[$timer->getId()] = $timer;

        return $timer->getId();
    }

    /**
     * Adds a new periodic timer to the manager.
     *
     * @param  float  $interval  The interval in seconds between executions.
     * @param  callable  $callback  The callback to execute on each interval.
     * @param  int|null  $maxExecutions  Maximum executions (null for infinite).
     * @return string The unique ID of the created periodic timer.
     */
    public function addPeriodicTimer(float $interval, callable $callback, ?int $maxExecutions = null): string
    {
        $periodicTimer = new PeriodicTimer($interval, $callback, $maxExecutions);
        $this->timers[$periodicTimer->getId()] = $periodicTimer;

        return $periodicTimer->getId();
    }

    /**
     * Cancels a pending timer by its unique ID.
     *
     * @param  string  $timerId  The ID of the timer to cancel.
     * @return bool True if the timer was found and canceled, false otherwise.
     */
    public function cancelTimer(string $timerId): bool
    {
        if (isset($this->timers[$timerId])) {
            unset($this->timers[$timerId]);

            return true;
        }

        return false;
    }

    /**
     * Checks if a timer exists and is currently active.
     *
     * @param  string  $timerId  The ID of the timer to check.
     * @return bool True if the timer exists.
     */
    public function hasTimer(string $timerId): bool
    {
        return isset($this->timers[$timerId]);
    }

    /**
     * Processes all timers and executes any that are ready.
     *
     * This method should be called on each tick of the event loop. It delegates
     * to the execution handler for regular timers, and handles periodic timers directly.
     *
     * @return bool True if at least one timer was executed.
     */
    public function processTimers(): bool
    {
        $currentTime = microtime(true);

        $regularExecuted = $this->processRegularTimers($currentTime);
        $periodicExecuted = $this->processPeriodicTimers($currentTime);

        return $regularExecuted || $periodicExecuted;
    }

    /**
     * Checks if there are any pending timers.
     *
     * @return bool True if there is at least one active timer.
     */
    public function hasTimers(): bool
    {
        return count($this->timers) > 0;
    }

    /**
     * Calculates the time in seconds until the next timer is due to fire.
     *
     * This is used by the event loop to determine the optimal sleep duration.
     *
     * @return float|null The delay until the next timer, or null if no timers are pending.
     */
    public function getNextTimerDelay(): ?float
    {
        $currentTime = microtime(true);

        // Convert mixed Timer|PeriodicTimer array to Timer array for calculateDelay
        $regularTimers = array_filter($this->timers, fn ($timer): bool => $timer instanceof Timer);
        /** @var array<Timer> $regularTimersTyped */
        $regularTimersTyped = array_values($regularTimers);

        return $this->scheduleHandler->calculateDelay($regularTimersTyped, $currentTime);
    }

    /**
     * Clear all pending timers.
     * Used during forced shutdown to prevent hanging.
     */
    public function clearAllTimers(): void
    {
        $this->timers = [];
    }

    /**
     * Get statistics about timers (backward compatible addition).
     *
     * @return array<string, mixed>
     */
    public function getTimerStats(): array
    {
        $regularCount = 0;
        $periodicCount = 0;
        $totalExecutions = 0;

        foreach ($this->timers as $timer) {
            if ($timer instanceof PeriodicTimer) {
                $periodicCount++;
                $totalExecutions += $timer->getExecutionCount();
            } else {
                $regularCount++;
            }
        }

        return [
            'regular_timers' => $regularCount,
            'periodic_timers' => $periodicCount,
            'total_timers' => count($this->timers),
            'total_periodic_executions' => $totalExecutions,
        ];
    }

    /**
     * Get information about a specific timer (backward compatible addition).
     *
     * @param  string  $timerId  The timer ID to get info for
     * @return array<string, mixed>|null Timer information or null if not found
     */
    public function getTimerInfo(string $timerId): ?array
    {
        if (! isset($this->timers[$timerId])) {
            return null;
        }

        $timer = $this->timers[$timerId];
        $baseInfo = [
            'id' => $timer->getId(),
            'execute_at' => $timer->getExecuteAt(),
            'is_ready' => $timer->isReady(microtime(true)),
        ];

        if ($timer instanceof PeriodicTimer) {
            $baseInfo['type'] = 'periodic';
            $baseInfo['interval'] = $timer->getInterval();
            $baseInfo['execution_count'] = $timer->getExecutionCount();
            $baseInfo['remaining_executions'] = $timer->getRemainingExecutions();
            $baseInfo['should_continue'] = $timer->shouldContinue();
        } else {
            $baseInfo['type'] = 'regular';
        }

        return $baseInfo;
    }

    /**
     * Process regular (one-time) timers using the existing execution handler.
     *
     * @param  float  $currentTime  Current timestamp
     * @return bool True if any regular timers were executed
     */
    private function processRegularTimers(float $currentTime): bool
    {
        $executed = false;

        foreach ($this->timers as $timerId => $timer) {
            if ($timer instanceof PeriodicTimer) {
                continue;
            }

            if ($timer->isReady($currentTime)) {
                $this->executionHandler->executeTimer($timer);
                unset($this->timers[$timerId]);
                $executed = true;
            }
        }

        return $executed;
    }

    /**
     * Process periodic timers and remove completed ones.
     *
     * @param  float  $currentTime  Current timestamp
     * @return bool True if any periodic timers were executed
     */
    private function processPeriodicTimers(float $currentTime): bool
    {
        $hasExecutedAny = false;
        $timersToRemove = [];

        foreach ($this->timers as $timerId => $timer) {
            if (! $timer instanceof PeriodicTimer) {
                continue;
            }

            if ($timer->isReady($currentTime)) {
                $timer->execute();
                $hasExecutedAny = true;

                // Remove completed periodic timers
                if (! $timer->shouldContinue()) {
                    $timersToRemove[] = $timerId;
                }
            }
        }

        // Clean up completed periodic timers
        foreach ($timersToRemove as $timerId) {
            unset($this->timers[$timerId]);
        }

        return $hasExecutedAny;
    }
}
