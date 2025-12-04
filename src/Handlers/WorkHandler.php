<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Handlers;

use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\Interfaces\FileManagerInterface;
use Hibla\EventLoop\Interfaces\HttpRequestManagerInterface;
use Hibla\EventLoop\Interfaces\SignalManagerInterface;
use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\Interfaces\TimerManagerInterface;
use Hibla\EventLoop\Interfaces\WorkHandlerInterface;

final class WorkHandler implements WorkHandlerInterface
{
    public function __construct(
        private TimerManagerInterface $timerManager,
        private HttpRequestManagerInterface $httpRequestManager,
        private StreamManagerInterface $streamManager,
        private FiberManagerInterface $fiberManager,
        private TickHandler $tickHandler,
        private FileManagerInterface $fileManager,
        private SignalManagerInterface $signalManager,
    ) {
    }

    public function hasWork(): bool
    {
        return $this->tickHandler->hasWork()
            || $this->timerManager->hasTimers()
            || $this->httpRequestManager->hasRequests()
            || $this->fileManager->hasWork()
            || $this->streamManager->hasWatchers()
            || $this->fiberManager->hasFibers()
            || $this->signalManager->hasSignals();
    }

    /**
     * Process one full cycle of work following Node.js event loop semantics:
     * 1. Signal Handling
     * 2. NextTick callbacks (highest priority)
     * 3. Microtasks (drained completely)
     * 4. Timer Phase - process timers one at a time
     * 5. Pending I/O Callbacks
     * 6. Poll Phase - I/O Operations (if any)
     * 7. Check Phase - setImmediate() callbacks (drains completely, including new ones)
     * 8. Fibers
     * 9. Close Callbacks Phase (Deferred)
     *
     * NOTE: Check phase must complete before returning to Timers phase
     */
    public function processWork(): bool
    {
        $workDone = false;

        if ($this->signalManager->processSignals()) {
            $workDone = true;
        }

        if ($this->processTicksAndMicrotasks()) {
            $workDone = true;
        }

        if ($this->processTimersIndividually()) {
            $workDone = true;
        }

        $hasIO = $this->httpRequestManager->hasRequests()
            || $this->streamManager->hasWatchers()
            || $this->fileManager->hasWork();

        if ($hasIO) {
            if ($this->processIOOperations()) {
                $workDone = true;
                $this->processTicksAndMicrotasks();
            }
        }

        if ($this->fiberManager->processFibers()) {
            $workDone = true;
            $this->processTicksAndMicrotasks();
        }

        if ($this->processCheckPhase()) {
            $workDone = true;
        }

        $hasPendingWork = $this->tickHandler->hasTickCallbacks()
            || $this->tickHandler->hasMicrotaskCallbacks()
            || $this->tickHandler->hasImmediateCallbacks()
            || $this->timerManager->hasTimers()
            || $this->httpRequestManager->hasRequests()
            || $this->fileManager->hasWork()
            || $this->streamManager->hasWatchers()
            || $this->fiberManager->hasFibers()
            || $this->signalManager->hasSignals();

        if (! $hasPendingWork && $this->tickHandler->hasDeferredCallbacks()) {
            if ($this->tickHandler->processDeferredCallbacks()) {
                $workDone = true;
                $this->processTicksAndMicrotasks();
            }
        }

        return $workDone;
    }

    /**
     * Process the Check phase (setImmediate callbacks).
     * This phase must completely drain all setImmediate callbacks,
     * including ones added during processing, before moving to next phase.
     *
     * @return bool True if any work was processed
     */
    private function processCheckPhase(): bool
    {
        $workDone = false;

        while ($this->tickHandler->hasImmediateCallbacks()) {
            if ($this->tickHandler->processImmediateCallbacks()) {
                $workDone = true;
                $this->processTicksAndMicrotasks();
            } else {
                break;
            }
        }

        return $workDone;
    }

    /**
     * @return bool True if any timers were processed
     */
    private function processTimersIndividually(): bool
    {
        $workDone = false;

        while ($this->timerManager->hasReadyTimers()) {
            if ($this->timerManager->processTimers()) {
                $workDone = true;
                $this->processTicksAndMicrotasks();
            } else {
                break;
            }
        }

        return $workDone;
    }

    /**
     * Process nextTick callbacks and microtasks with correct priority.
     * NextTick always has higher priority than microtasks.
     *
     * This keeps looping until both the nextTick and microtask queues are
     * completely empty, ensuring proper draining semantics.
     *
     * @return bool True if any work was processed
     */
    private function processTicksAndMicrotasks(): bool
    {
        $workDone = false;

        while ($this->tickHandler->hasTickCallbacks() || $this->tickHandler->hasMicrotaskCallbacks()) {
            if ($this->tickHandler->processNextTickCallbacks()) {
                $workDone = true;
            }

            if (! $this->tickHandler->hasTickCallbacks()) {
                if ($this->tickHandler->processMicrotasks()) {
                    $workDone = true;
                }
            }
        }

        return $workDone;
    }

    /**
     * @return bool True if any I/O work was performed
     */
    private function processIOOperations(): bool
    {
        $workDone = false;

        if ($this->httpRequestManager->processRequests()) {
            $workDone = true;
        }

        if ($this->streamManager->hasWatchers()) {
            if ($this->streamManager->processStreams()) {
                $workDone = true;
            }
        }

        if ($this->fileManager->processFileOperations()) {
            $workDone = true;
        }

        return $workDone;
    }
}
