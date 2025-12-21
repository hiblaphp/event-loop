<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use Fiber;
use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use SplObjectStorage;
use SplQueue;

final class FiberManager implements FiberManagerInterface
{
    /**
     * @var SplQueue<Fiber<null, mixed, mixed, mixed>>
     */
    private SplQueue $readyQueue;

    /**
     * @var SplObjectStorage<Fiber<null, mixed, mixed, mixed>, null>
     */
    private SplObjectStorage $suspendedFibers;

    private int $activeFiberCount = 0;

    private bool $acceptingNewFibers = true;

    public function __construct()
    {
        $this->readyQueue = new SplQueue();
        $this->suspendedFibers = new SplObjectStorage();
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

            if ($this->suspendedFibers->offsetExists($fiber)) {
                $this->suspendedFibers->offsetUnset($fiber);
            }

            return;
        }

        // Only schedule if it's actually suspended
        if ($this->suspendedFibers->offsetExists($fiber)) {
            $this->suspendedFibers->offsetUnset($fiber);
            $this->readyQueue->enqueue($fiber);
        }
    }

    /**
     * @inheritdoc
     */
    public function processFibers(): bool
    {
        // CRITICAL: Fibers should only be resumed when explicitly scheduled via scheduleFiber()

        if ($this->readyQueue->isEmpty()) {
            return false;
        }

        $processedCount = 0;
        $batchSize = $this->readyQueue->count();

        while ($batchSize-- > 0 && ! $this->readyQueue->isEmpty()) {
            $fiber = $this->readyQueue->dequeue();

            try {
                if (! $fiber->isStarted()) {
                    $fiber->start();
                } elseif ($fiber->isSuspended()) {
                    $fiber->resume();
                } else {
                    continue;
                }

                $processedCount++;

                if ($fiber->isSuspended()) {
                    // Track suspended fibers - they'll only be resumed when explicitly scheduled
                    $this->suspendedFibers->offsetSet($fiber, null);
                } elseif ($fiber->isTerminated()) {
                    $this->activeFiberCount--;

                    if ($this->suspendedFibers->offsetExists($fiber)) {
                        $this->suspendedFibers->offsetUnset($fiber);
                    }
                }
            } catch (\Throwable $e) {
                $this->activeFiberCount--;

                if ($this->suspendedFibers->offsetExists($fiber)) {
                    $this->suspendedFibers->offsetUnset($fiber);
                }

                throw $e;
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
        return ! $this->readyQueue->isEmpty() || $this->suspendedFibers->count() > 0;
    }

    /**
     * @inheritdoc
     */
    public function clearFibers(): void
    {
        $this->readyQueue = new SplQueue();
        $this->suspendedFibers = new SplObjectStorage();
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
}