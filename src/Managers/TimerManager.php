<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use Hibla\EventLoop\Interfaces\TimerManagerInterface;
use Hibla\EventLoop\ValueObjects\PeriodicTimer;
use Hibla\EventLoop\ValueObjects\Timer;
use SplPriorityQueue;

final class TimerManager implements TimerManagerInterface
{
    /**
     * @var array<int, Timer|PeriodicTimer>
     */
    private array $timers = [];

    /**
     * @var SplPriorityQueue<int, int>
     */
    private SplPriorityQueue $timerQueue;

    private bool $queueNeedsRebuild = false;
    
    private int $nextId = 0;

    public function __construct()
    {
        $this->timerQueue = new SplPriorityQueue();
        $this->timerQueue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    }

    /**
     * @inheritDoc
     */
    public function addTimer(float $delay, callable $callback): string
    {
        $id = $this->nextId++;
        $timer = new Timer($id, $delay, $callback);
        $this->timers[$id] = $timer;

        $this->timerQueue->insert($id, (int)(-$timer->executeAt * 1000000));

        return (string)$id;
    }

    /**
     * @inheritDoc
     */
    public function addPeriodicTimer(float $interval, callable $callback, ?int $maxExecutions = null): string
    {
        $id = $this->nextId++;
        $periodicTimer = new PeriodicTimer($id, $interval, $callback, $maxExecutions);
        $this->timers[$id] = $periodicTimer;

        $this->timerQueue->insert($id, (int)(-$periodicTimer->executeAt * 1000000));

        return (string)$id;
    }

    /**
     * @inheritDoc
     */
    public function cancelTimer(string $timerId): bool
    {
        $id = (int)$timerId;
        
        if (isset($this->timers[$id])) {
            unset($this->timers[$id]);
            $this->queueNeedsRebuild = true;
            return true;
        }

        return false;
    }
    
    /**
     * @inheritDoc
     */
    public function hasTimer(string $timerId): bool
    {
        return isset($this->timers[(int)$timerId]);
    }
    
    /**
     * @inheritDoc
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

        while (!$this->timerQueue->isEmpty()) {
            $item = $this->timerQueue->top();
            assert(\is_array($item) && isset($item['data']));
            $id = $item['data'];

            if (!isset($this->timers[$id])) {
                $this->timerQueue->extract();
                continue;
            }

            return $this->timers[$id]->isReady($currentTime);
        }

        return false;
    }
    
    /**
     * @inheritDoc
     */
    public function processTimers(): bool
    {
        if ($this->queueNeedsRebuild) {
            $this->rebuildQueue();
        }

        if ($this->timerQueue->isEmpty()) {
            return false;
        }

        $currentTime = microtime(true);

        while (!$this->timerQueue->isEmpty()) {
            $item = $this->timerQueue->top();
            assert(\is_array($item) && isset($item['data']));
            $id = $item['data'];

            if (!isset($this->timers[$id])) {
                $this->timerQueue->extract();
                continue;
            }

            $timer = $this->timers[$id];

            if (!$timer->isReady($currentTime)) {
                return false;
            }

            $this->timerQueue->extract();

            if ($timer instanceof PeriodicTimer) {
                $timer->execute();

                if ($timer->shouldContinue()) {
                    $this->timerQueue->insert($id, (int)(-$timer->executeAt * 1000000));
                } else {
                    unset($this->timers[$id]);
                }
            } else {
                $timer->execute();
                unset($this->timers[$id]);
            }

            return true;
        }

        return false;
    }
        
    /**
     * @inheritDoc
     */
    public function hasTimers(): bool
    {
        return count($this->timers) > 0;
    }
        
    /**
     * @inheritDoc
     */
    public function getNextTimerDelay(): ?float
    {
        if ($this->queueNeedsRebuild) {
            $this->rebuildQueue();
        }

        if ($this->timerQueue->isEmpty()) {
            return null;
        }

        $currentTime = microtime(true);

        while (!$this->timerQueue->isEmpty()) {
            $item = $this->timerQueue->top();
            assert(\is_array($item) && isset($item['data']));
            $id = $item['data'];

            if (!isset($this->timers[$id])) {
                $this->timerQueue->extract();
                continue;
            }

            $delay = $this->timers[$id]->executeAt - $currentTime;
            return $delay > 0 ? $delay : 0.0;
        }

        return null;
    }
        
    /**
     * @inheritDoc
     */
    public function clearAllTimers(): void
    {
        $this->timers = [];
        $this->timerQueue = new SplPriorityQueue();
        $this->timerQueue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $this->queueNeedsRebuild = false;
        $this->nextId = 0;
    }

    private function rebuildQueue(): void
    {
        $this->timerQueue = new SplPriorityQueue();
        $this->timerQueue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

        foreach ($this->timers as $id => $timer) {
            $this->timerQueue->insert($id, (int)(-$timer->executeAt * 1000000));
        }

        $this->queueNeedsRebuild = false;
    }
}