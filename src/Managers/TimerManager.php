<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use Hibla\EventLoop\Interfaces\TimerManagerInterface;
use Hibla\EventLoop\IOHandlers\Timer\TimerExecutionHandler;
use Hibla\EventLoop\IOHandlers\Timer\TimerScheduleHandler;
use Hibla\EventLoop\ValueObjects\PeriodicTimer;
use Hibla\EventLoop\ValueObjects\Timer;
use SplPriorityQueue;

final class TimerManager implements TimerManagerInterface
{
    /**
     * @var array<string, Timer|PeriodicTimer>
     */
    private array $timers = [];

    /**
     * @var SplPriorityQueue<int, Timer|PeriodicTimer>
     */
    private SplPriorityQueue $timerQueue;

    private readonly TimerExecutionHandler $executionHandler;
    private readonly TimerScheduleHandler $scheduleHandler;

    private bool $queueNeedsRebuild = false;

    public function __construct()
    {
        $this->executionHandler = new TimerExecutionHandler();
        $this->scheduleHandler = new TimerScheduleHandler();
        $this->timerQueue = new SplPriorityQueue();
        $this->timerQueue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    }

    public function addTimer(float $delay, callable $callback): string
    {
        $timer = $this->scheduleHandler->createTimer($delay, $callback);
        $this->timers[$timer->getId()] = $timer;

        $this->timerQueue->insert($timer, (int)(-$timer->getExecuteAt() * 1000000));

        return $timer->getId();
    }

    public function addPeriodicTimer(float $interval, callable $callback, ?int $maxExecutions = null): string
    {
        $periodicTimer = new PeriodicTimer($interval, $callback, $maxExecutions);
        $this->timers[$periodicTimer->getId()] = $periodicTimer;

        $this->timerQueue->insert($periodicTimer, (int)(-$periodicTimer->getExecuteAt() * 1000000));

        return $periodicTimer->getId();
    }

    public function cancelTimer(string $timerId): bool
    {
        if (isset($this->timers[$timerId])) {
            unset($this->timers[$timerId]);

            $this->queueNeedsRebuild = true;

            return true;
        }

        return false;
    }

    public function hasTimer(string $timerId): bool
    {
        return isset($this->timers[$timerId]);
    }

    /**
     * Check if there are any timers ready to execute now.
     *
     * @return bool True if at least one timer is ready
     */
    public function hasReadyTimers(): bool
    {
        if ($this->queueNeedsRebuild) {
            $this->rebuildQueue();
        }

        if ($this->timerQueue->isEmpty()) {
            return false;
        }

        $currentTime = microtime(true);

        $this->timerQueue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

        // Check if the top timer (earliest) is ready
        while (! $this->timerQueue->isEmpty()) {
            /** @var array{priority: int, data: Timer|PeriodicTimer} $item */
            $item = $this->timerQueue->top();
            $timer = $item['data'];

            // Skip cancelled timers
            if (! isset($this->timers[$timer->getId()])) {
                $this->timerQueue->extract();

                continue;
            }

            return $timer->isReady($currentTime);
        }

        return false;
    }

    public function processTimers(): bool
    {
        if ($this->queueNeedsRebuild) {
            $this->rebuildQueue();
        }

        if ($this->timerQueue->isEmpty()) {
            return false;
        }

        $currentTime = microtime(true);

        // Find and process the first ready timer
        while (! $this->timerQueue->isEmpty()) {
            $this->timerQueue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
            /** @var array{priority: int, data: Timer|PeriodicTimer} $item */
            $item = $this->timerQueue->top();
            $timer = $item['data'];

            // Skip cancelled timers
            if (! isset($this->timers[$timer->getId()])) {
                $this->timerQueue->extract();

                continue;
            }

            // If not ready, no more timers are ready (queue is sorted)
            if (! $timer->isReady($currentTime)) {
                return false;
            }

            // Extract and execute this timer
            $this->timerQueue->extract();

            if ($timer instanceof PeriodicTimer) {
                $timer->execute();

                if ($timer->shouldContinue()) {
                    // Re-insert for next execution
                    $this->timerQueue->insert($timer, (int)(-$timer->getExecuteAt() * 1000000));
                } else {
                    unset($this->timers[$timer->getId()]);
                }
            } else {
                $this->executionHandler->executeTimer($timer);
                unset($this->timers[$timer->getId()]);
            }

            // Return after processing ONE timer
            return true;
        }

        return false;
    }

    public function hasTimers(): bool
    {
        return \count($this->timers) > 0;
    }

    public function getNextTimerDelay(): ?float
    {
        if ($this->queueNeedsRebuild) {
            $this->rebuildQueue();
        }

        if ($this->timerQueue->isEmpty()) {
            return null;
        }

        $currentTime = microtime(true);

        $this->timerQueue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

        while (! $this->timerQueue->isEmpty()) {
            /** @var array{priority: int, data: Timer|PeriodicTimer} $item */
            $item = $this->timerQueue->top();
            $timer = $item['data'];

            if (! isset($this->timers[$timer->getId()])) {
                $this->timerQueue->extract();

                continue;
            }

            $delay = $timer->getExecuteAt() - $currentTime;

            return $delay > 0 ? $delay : 0.0;
        }

        return null;
    }

    public function clearAllTimers(): void
    {
        $this->timers = [];
        $this->timerQueue = new SplPriorityQueue();
        $this->timerQueue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $this->queueNeedsRebuild = false;
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
            'queue_needs_rebuild' => $this->queueNeedsRebuild,
        ];
    }

    /**
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

    private function rebuildQueue(): void
    {
        $this->timerQueue = new SplPriorityQueue();
        $this->timerQueue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

        foreach ($this->timers as $timer) {
            $this->timerQueue->insert($timer, (int)(-$timer->getExecuteAt() * 1000000));
        }

        $this->queueNeedsRebuild = false;
    }
}
