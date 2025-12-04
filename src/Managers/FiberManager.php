<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use Fiber;
use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use SplQueue;

final class FiberManager implements FiberManagerInterface
{
    /**
     * @var SplQueue<Fiber<null, mixed, mixed, mixed>>
     */
    private SplQueue $readyQueue;

    /**
     * @var SplQueue<Fiber<null, mixed, mixed, mixed>>
     */
    private SplQueue $suspendedQueue;

    private int $activeFiberCount = 0;

    private bool $acceptingNewFibers = true;

    public function __construct()
    {
        $this->readyQueue = new SplQueue();
        $this->suspendedQueue = new SplQueue();
    }

    /**
     * @inheritdoc
     */
    public function addFiber(Fiber $fiber): void
    {
        if (! $this->acceptingNewFibers || $fiber->isTerminated()) {
            return;
        }

        $this->readyQueue->enqueue($fiber);
        $this->activeFiberCount++;
    }

    /**
     * @inheritdoc
     */
    public function scheduleFiber(Fiber $fiber): void
    {
        if ($fiber->isTerminated()) {
            $this->activeFiberCount--;

            return;
        }

        $this->readyQueue->enqueue($fiber);
    }

    /**
     * @inheritdoc
     */
    public function processFibers(): bool
    {
        $this->moveAllSuspendedToReady();

        if ($this->readyQueue->isEmpty()) {
            return false;
        }

        $processedCount = 0;

        $batchSize = $this->readyQueue->count();

        while ($batchSize-- > 0) {
            $fiber = $this->readyQueue->dequeue();
            if ($fiber->isStarted()) {
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
            } else {
                $fiber->start();
            }

            $processedCount++;

            if ($fiber->isSuspended()) {
                $this->suspendedQueue->enqueue($fiber);
            } elseif ($fiber->isTerminated()) {
                $this->activeFiberCount--;
            }
        }

        return $processedCount > 0;
    }

    /**
     * @inheritdoc
     */
    public function hasFibers(): bool
    {
        return $this->activeFiberCount > 0;
    }

    /**
     * @inheritdoc
     */
    public function hasActiveFibers(): bool
    {
        return ! $this->readyQueue->isEmpty() || ! $this->suspendedQueue->isEmpty();
    }

    /**
     * @inheritdoc
     */
    public function clearFibers(): void
    {
        $this->readyQueue = new SplQueue();
        $this->suspendedQueue = new SplQueue();
        $this->activeFiberCount = 0;
    }

    /**
     * @inheritdoc
     */
    public function prepareForShutdown(): void
    {
        $this->acceptingNewFibers = false;
    }

    /**
     * @inheritdoc
     */
    public function isAcceptingNewFibers(): bool
    {
        return $this->acceptingNewFibers;
    }

    private function moveAllSuspendedToReady(): void
    {
        while (! $this->suspendedQueue->isEmpty()) {
            $this->readyQueue->enqueue($this->suspendedQueue->dequeue());
        }
    }
}