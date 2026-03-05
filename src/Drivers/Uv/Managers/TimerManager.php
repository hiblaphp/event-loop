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
     * @var resource
     */
    private $uvLoop;

    /**
     * @var resource The ONLY libuv timer handle used to wake up uv_run
     */
    private $masterTimer;

    /**
     * @var array<int, Timer|PeriodicTimer>
     */
    private array $timers = [];

    /**
     * @var SplPriorityQueue<int, int>
     */
    private SplPriorityQueue $timerQueue;

    private int $nextId = 0;

    /**
     * @var \Closure Shared callback — just wakes PHP up, execution happens in WorkHandler
     */
    private \Closure $masterCallback;

    public function __construct($uvLoop)
    {
        $this->uvLoop = $uvLoop;
        $this->masterTimer = \uv_timer_init($this->uvLoop);

        $this->timerQueue = new SplPriorityQueue();
        $this->timerQueue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

        // The master callback is intentionally a no-op.
        // Its only job is to make uv_run() wake up when a timer is due.
        // WorkHandler pulls the ready callbacks via collectReadyTimers()
        // and executes them one at a time with microtask draining between each,
        // matching Node.js timer phase semantics.
        $this->masterCallback = function (): void {
            // No-op: intentional. See above.
        };
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

        return (string) $id;
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

        return (string) $id;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelTimer(string $timerId): bool
    {
        $id = (int) $timerId;

        if (isset($this->timers[$id])) {
            unset($this->timers[$id]);

            return true;
        }

        return false;
    }

    /**
     * Collect all ready timer callbacks without executing them.
     *
     * Returns an array of callables so WorkHandler can execute them
     * one at a time with microtask draining between each, exactly
     * matching Node.js timer phase semantics where nextTick and
     * microtask queues are fully drained after every timer callback.
     *
     * @return list<callable>
     */
    public function collectReadyTimers(): array
    {
        $callbacks = [];

        if ($this->timerQueue->isEmpty()) {
            return $callbacks;
        }

        $currentTime = hrtime(true);

        while (! $this->timerQueue->isEmpty()) {
            $item = $this->timerQueue->top();
            assert(\is_array($item) && isset($item['data']));
            $id = $item['data'];

            // Skip cancelled timers
            if (! isset($this->timers[$id])) {
                $this->timerQueue->extract();
                continue;
            }

            $timer = $this->timers[$id];

            if (! $timer->isReady($currentTime)) {
                break;
            }

            $this->timerQueue->extract();

            if ($timer instanceof PeriodicTimer) {
                $callbacks[] = function () use ($timer, $id): void {
                    $timer->execute();

                    if ($timer->shouldContinue()) {
                        $this->timerQueue->insert($id, -$timer->executeAt);
                    } else {
                        unset($this->timers[$id]);
                    }
                };
            } else {
                $callbacks[] = function () use ($timer, $id): void {
                    $timer->execute();
                    unset($this->timers[$id]);
                };
            }
        }

        return $callbacks;
    }

    /**
     * {@inheritdoc}
     *
     * Executes all collected ready timers and reschedules the master handle.
     * In the UV driver, WorkHandler bypasses this and calls collectReadyTimers()
     * directly so it can drain ticks between each callback. This method exists
     * to satisfy the interface and for non-WorkHandler callers.
     */
    public function processTimers(): bool
    {
        $callbacks = $this->collectReadyTimers();

        foreach ($callbacks as $callback) {
            $callback();
        }

        $this->scheduleMasterTimer();

        return \count($callbacks) > 0;
    }

    /**
     * Reschedule the master libuv timer without executing any callbacks.
     * Called by WorkHandler after it has finished executing collected timers.
     */
    public function rescheduleMaster(): void
    {
        $this->scheduleMasterTimer();
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
            assert(\is_array($item) && isset($item['data']));
            $id = $item['data'];

            if (! isset($this->timers[$id])) {
                $this->timerQueue->extract();
                continue;
            }

            $delayNs  = $this->timers[$id]->executeAt - $currentTime;
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
        return isset($this->timers[(int) $timerId]);
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
     * Always returns false — uv_run wakes PHP up when timers are due,
     * so the PHP-land loop never needs to poll for readiness itself.
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
        if (\uv_is_active($this->masterTimer)) {
            \uv_timer_stop($this->masterTimer);
        }

        $this->timers    = [];
        $this->timerQueue = new SplPriorityQueue();
        $this->timerQueue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $this->nextId    = 0;
    }

    private function scheduleMasterTimer(): void
    {
        if (\uv_is_active($this->masterTimer)) {
            \uv_timer_stop($this->masterTimer);
        }

        $nextDelay = $this->getNextTimerDelay();

        if ($nextDelay !== null) {
            $delayMs = (int) \ceil($nextDelay * 1000);

            if ($delayMs < 0) {
                $delayMs = 0;
            }

            \uv_timer_start($this->masterTimer, $delayMs, 0, $this->masterCallback);
        }
    }
}