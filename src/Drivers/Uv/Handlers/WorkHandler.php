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
        private SignalManagerInterface $signalManager,
    ) {
        $this->uvLoop = $uvLoop;
    }

    public function hasWork(): bool
    {
        return $this->tickHandler->hasWork()
            || $this->timerManager->hasTimers()
            || $this->httpRequestManager->hasRequests()
            || $this->streamManager->hasWatchers()
            || $this->fiberManager->hasFibers()
            || $this->signalManager->hasSignals();
    }

    public function processWork(): bool
    {
        $workDone = false;

        // 1. Pre-loop: Drain NextTick & Microtasks
        if ($this->processTicksAndMicrotasks()) {
            $workDone = true;
        }

        // 2. Determine UV Run Mode
        // We poll (NOWAIT) if we have immediate fibers or immediate callbacks.
        // We do NOT check deferreds here, as deferreds wait for an idle loop.
        $hasImmediateWork = $this->tickHandler->hasImmediateCallbacks()
            || $this->fiberManager->hasReadyFibers();

        $flags = $hasImmediateWork ? \UV::RUN_NOWAIT : \UV::RUN_ONCE;

        // 3. Run LibUV (Processes Timers, Streams, File System, Signals natively)
        // If an event fired, callbacks were executed inside this C-function.
        $uvStatus = \uv_run($this->uvLoop, $flags);
        
        if ($uvStatus !== 0) {
            $workDone = true;
        }

        // IMPORTANT: Drain microtasks queued by any I/O or Timer callbacks 
        // that just fired inside uv_run.
        if ($this->processTicksAndMicrotasks()) {
            $workDone = true;
        }

        // 4. Fiber Phase
        while ($this->fiberManager->hasReadyFibers()) {
            if ($this->fiberManager->processFibers()) {
                $workDone = true;
                $this->processTicksAndMicrotasks();
            } else {
                break;
            }
        }

        // 5. HTTP (cURL) Phase
        // Note: For full uv compliance later, curl multi can be tied to uv_poll.
        if ($this->httpRequestManager->processRequests()) {
            $workDone = true;
            $this->processTicksAndMicrotasks();
        }

        // 6. Check Phase (setImmediate)
        if ($this->processCheckPhase()) {
            $workDone = true;
        }

        // 7. Deferred Phase (Cleanup/Idle Phase)
        // Only process deferreds if there is NO pending work (timers, io, fibers, etc).
        $hasPendingWork = $this->tickHandler->hasTickCallbacks()
            || $this->tickHandler->hasMicrotaskCallbacks()
            || $this->tickHandler->hasImmediateCallbacks()
            || $this->timerManager->hasTimers()
            || $this->httpRequestManager->hasRequests()
            || $this->streamManager->hasWatchers()
            || $this->fiberManager->hasFibers();

        if (! $hasPendingWork && $this->tickHandler->hasDeferredCallbacks()) {
            if ($this->tickHandler->processDeferredCallbacks()) {
                $workDone = true;
                $this->processTicksAndMicrotasks();
            }
        }

        // We return true because the loop might simply be sleeping waiting for an event.
        // Returning true prevents the main factory loop from exiting prematurely.
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
            
            // Microtasks can be queued by setImmediate callbacks
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