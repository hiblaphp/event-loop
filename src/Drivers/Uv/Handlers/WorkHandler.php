<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Drivers\Uv\Handlers;

use Hibla\EventLoop\Handlers\TickHandler;
use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\Interfaces\FileWatcherManagerInterface;
use Hibla\EventLoop\Interfaces\HttpRequestManagerInterface;
use Hibla\EventLoop\Interfaces\SignalManagerInterface;
use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\Interfaces\TimerManagerInterface;
use Hibla\EventLoop\Interfaces\WorkHandlerInterface;

final class WorkHandler implements WorkHandlerInterface
{
    /**
     * @var resource
     */
    private $uvLoop;

    public function __construct(
        $uvLoop,
        private TimerManagerInterface $timerManager,
        private HttpRequestManagerInterface $httpRequestManager,
        private StreamManagerInterface $streamManager,
        private FiberManagerInterface $fiberManager,
        private TickHandler $tickHandler,
        private FileWatcherManagerInterface $fileWatcherManager,
        private SignalManagerInterface $signalManager,
    ) {
        $this->uvLoop = $uvLoop;
    }

    public function hasWork(): bool
    {
        return $this->tickHandler->hasWork()
            || $this->timerManager->hasTimers()
            || $this->httpRequestManager->hasRequests()
            || $this->fileWatcherManager->hasWatchers()
            || $this->streamManager->hasWatchers()
            || $this->fiberManager->hasFibers()
            || $this->signalManager->hasSignals();
    }

    public function processWork(): bool
    {
        $workDone = false;

        // 1. Pre-loop: NextTick & Microtasks
        if ($this->processTicksAndMicrotasks()) {
            $workDone = true;
        }

        // 2. Determine UV Run Mode
        // We poll (NOWAIT) if we have immediate fibers or immediate callbacks.
        $hasImmediateWork = $this->tickHandler->hasImmediateCallbacks()
            || $this->fiberManager->hasReadyFibers();
            // Note: We removed hasDeferredCallbacks() from here because deferreds 
            // shouldn't force a spin; they wait for idle.

        $flags = $hasImmediateWork ? \UV::RUN_NOWAIT : \UV::RUN_ONCE;

        // 3. Run LibUV
        \uv_run($this->uvLoop, $flags);

        // 4. Manual Phases
        while ($this->fiberManager->hasReadyFibers()) {
            if ($this->fiberManager->processFibers()) {
                $workDone = true;
                $this->processTicksAndMicrotasks();
            } else {
                break;
            }
        }

        if ($this->httpRequestManager->processRequests()) {
            $workDone = true;
        }

        // 5. Check Phase
        if ($this->processCheckPhase()) {
            $workDone = true;
        }

        // 6. Deferred Phase (Corrected Logic)
        // Only process deferreds if there is NO pending work (timers, io, fibers, etc)
        // This matches StreamSelect behavior.
        $hasPendingWork = $this->tickHandler->hasTickCallbacks()
            || $this->tickHandler->hasMicrotaskCallbacks()
            || $this->tickHandler->hasImmediateCallbacks()
            || $this->timerManager->hasTimers()
            || $this->httpRequestManager->hasRequests()
            || $this->fileWatcherManager->hasWatchers()
            || $this->streamManager->hasWatchers()
            || $this->fiberManager->hasFibers();

        if (! $hasPendingWork && $this->tickHandler->hasDeferredCallbacks()) {
            if ($this->tickHandler->processDeferredCallbacks()) {
                $workDone = true;
                $this->processTicksAndMicrotasks();
            }
        }

        return true;
    }

    private function processCheckPhase(): bool
    {
        $workDone = false;
        $queue = $this->tickHandler->swapImmediateQueue();

        while (! $queue->isEmpty()) {
            $callback = $queue->dequeue();
            $callback();
            $workDone = true;
            $this->processTicksAndMicrotasks();
        }

        return $workDone;
    }

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
}