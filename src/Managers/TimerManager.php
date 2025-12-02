<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use Hibla\EventLoop\Interfaces\TimerManagerInterface;
use Hibla\EventLoop\IOHandlers\Timer\TimerExecutionHandler;
use Hibla\EventLoop\IOHandlers\Timer\TimerScheduleHandler;
use Hibla\EventLoop\ValueObjects\PeriodicTimer;
use Hibla\EventLoop\ValueObjects\Timer;

final class TimerManager implements TimerManagerInterface
{
    /**
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
     * @param  float  $delay
     * @param  callable  $callback
     * @return string The unique ID of the created timer.
     */
    public function addTimer(float $delay, callable $callback): string
    {
        $timer = $this->scheduleHandler->createTimer($delay, $callback);
        $this->timers[$timer->getId()] = $timer;

        return $timer->getId();
    }

    /**
     * @param  float  $interval
     * @param  callable  $callback
     * @param  int|null  $maxExecutions
     * @return string The unique ID of the created periodic timer.
     */
    public function addPeriodicTimer(float $interval, callable $callback, ?int $maxExecutions = null): string
    {
        $periodicTimer = new PeriodicTimer($interval, $callback, $maxExecutions);
        $this->timers[$periodicTimer->getId()] = $periodicTimer;

        return $periodicTimer->getId();
    }

    /**
     * @param  string  $timerId
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
     * @param  string  $timerId  The ID of the timer to check.
     * @return bool True if the timer exists.
     */
    public function hasTimer(string $timerId): bool
    {
        return isset($this->timers[$timerId]);
    }

    /**
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
     * @return bool True if there is at least one active timer.
     */
    public function hasTimers(): bool
    {
        return \count($this->timers) > 0;
    }

    /**
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

    public function clearAllTimers(): void
    {
        $this->timers = [];
    }

    /**
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
     * @param  string  $timerId
     * @return array<string, mixed>|null
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
     * @param  float  $currentTime
     * @return bool
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
     * @param  float  $currentTime
     * @return bool
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
