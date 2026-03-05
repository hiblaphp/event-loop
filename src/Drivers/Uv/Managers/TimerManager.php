<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Drivers\Uv\Managers;

use Hibla\EventLoop\Interfaces\TimerManagerInterface;
use Hibla\EventLoop\ValueObjects\PeriodicTimer;
use Hibla\EventLoop\ValueObjects\Timer;
use SplPriorityQueue;

final class TimerManager implements TimerManagerInterface
{
    /**
     *  @var resource 
     */
    private $uvLoop;

    /**
     *  @var resource The ONLY libuv timer we will be using to schedule timers
     */
    private $masterTimer;

    /**
     *  @var array<int, Timer|PeriodicTimer> 
     */
    private array $timers = [];

    /**
     *  @var SplPriorityQueue<int, int> 
     */
    private SplPriorityQueue $timerQueue;

    private int $nextId = 0;

    /**
     *  @var \Closure Shared callback to prevent GC collection
     */
    private \Closure $masterCallback;

    public function __construct($uvLoop)
    {
        $this->uvLoop = $uvLoop;
        $this->masterTimer = \uv_timer_init($this->uvLoop);

        $this->timerQueue = new SplPriorityQueue();
        $this->timerQueue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

        // One single closure for the entire lifecycle
        $this->masterCallback = $this->processTimers(...);
    }

    /**
     * {@inheritdoc}
     */
    public function addTimer(float $delay, callable $callback): string
    {
        $id = $this->nextId++;
        $timer = new Timer($id, $delay, $callback);
        $this->timers[$id] = $timer;

        $this->timerQueue->insert($id, -$timer->executeAt);
        $this->scheduleMasterTimer();

        return (string)$id;
    }

    /**
     * {@inheritdoc}
     */
    public function addPeriodicTimer(float $interval, callable $callback, ?int $maxExecutions = null): string
    {
        $id = $this->nextId++;
        $periodicTimer = new PeriodicTimer($id, $interval, $callback, $maxExecutions);
        $this->timers[$id] = $periodicTimer;

        $this->timerQueue->insert($id, -$periodicTimer->executeAt);
        $this->scheduleMasterTimer();

        return (string)$id;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelTimer(string $timerId): bool
    {
        $id = (int)$timerId;

        if (isset($this->timers[$id])) {
            unset($this->timers[$id]);
            // No need to reschedule master immediately. It will wake up, 
            // see the timer is gone, and skip it.
            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function processTimers(): bool
    {
        if ($this->timerQueue->isEmpty()) {
            return false;
        }

        $currentTime = hrtime(true);
        $processed = false;

        while (! $this->timerQueue->isEmpty()) {
            $item = $this->timerQueue->top();
            $id = $item['data'];

            // Timer was cancelled
            if (! isset($this->timers[$id])) {
                $this->timerQueue->extract();
                continue;
            }

            $timer = $this->timers[$id];

            // If the top timer isn't ready, no other timer is ready
            if (! $timer->isReady($currentTime)) {
                break;
            }

            $this->timerQueue->extract();
            $processed = true;

            if ($timer instanceof PeriodicTimer) {
                $timer->execute();
                if ($timer->shouldContinue()) {
                    $this->timerQueue->insert($id, -$timer->executeAt);
                } else {
                    unset($this->timers[$id]);
                }
            } else {
                $timer->execute();
                unset($this->timers[$id]);
            }

            // Update time after executing a callback, as it might have taken a while
            $currentTime = hrtime(true);
        }

        // Schedule the next wake-up
        $this->scheduleMasterTimer();

        return $processed;
    }

    /**
     * {@inheritdoc}
     */
    public function getNextTimerDelay(): ?float
    {
        if ($this->timerQueue->isEmpty()) {
            return null;
        }

        $currentTime = hrtime(true);

        while (! $this->timerQueue->isEmpty()) {
            $item = $this->timerQueue->top();
            $id = $item['data'];

            if (! isset($this->timers[$id])) {
                $this->timerQueue->extract();
                continue;
            }

            $delayNs = $this->timers[$id]->executeAt - $currentTime;
            $delaySecs = $delayNs / 1_000_000_000;

            return $delaySecs > 0 ? $delaySecs : 0.0;
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function hasTimer(string $timerId): bool
    {
        return isset($this->timers[(int)$timerId]);
    }

    /**
     * {@inheritdoc}
     */
    public function hasTimers(): bool
    {
        return \count($this->timers) > 0;
    }

    /**
     * {@inheritdoc}
     * 
     *  WorkHandler relies on uv_run to trigger timers, so this is no-op.
     */
    public function hasReadyTimers(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function clearAllTimers(): void
    {
        foreach ($this->uvHandles as $handle) {
            @\uv_timer_stop($handle);
            \uv_close($handle);
        }

        $this->uvHandles = [];
        $this->timers = [];
        $this->nextId = 0;
    }

    private function scheduleMasterTimer(): void
    {
        @\uv_timer_stop($this->masterTimer);

        $nextDelay = $this->getNextTimerDelay();

        if ($nextDelay !== null) {
            // Use ceil to ensure we don't wake up 0.9ms early and spin
            $delayMs = (int) ceil($nextDelay * 1000);

            if ($delayMs < 0) {
                $delayMs = 0;
            }

            \uv_timer_start($this->masterTimer, $delayMs, 0, $this->masterCallback);
        }
    }
}
