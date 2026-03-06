<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Drivers\Uv\Handlers;

use Hibla\EventLoop\Handlers\TickHandler;
use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\Interfaces\HttpRequestManagerInterface;
use Hibla\EventLoop\Interfaces\SignalManagerInterface;
use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\Interfaces\UvTimerManagerInterface;
use Hibla\EventLoop\Interfaces\WorkHandlerInterface;

final class WorkHandler implements WorkHandlerInterface
{
    /**
     * @var \UVLoop
     */
    private \UVLoop $uvLoop;

    public function __construct(
        \UVLoop $uvLoop,
        private UvTimerManagerInterface $timerManager,
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

    /**
     * Process one full cycle of work following Node.js event loop semantics.
     *
     * The key difference from the StreamSelect driver is that libuv drives
     * I/O and timer wake-ups internally via uv_run(). WorkHandler's job is
     * to correctly interleave the PHP-land queues (nextTick, microtasks,
     * fibers, setImmediate) around those native events.
     *
     * Timer phase works as follows:
     *   1. uv_run() wakes up when a timer is due (master callback is a no-op).
     *   2. WorkHandler calls collectReadyTimers() to pull all due callbacks
     *      out of the queue without executing them yet.
     *   3. Each callback is executed individually, with nextTick + microtask
     *      queues fully drained after every single callback.
     *   This matches Node.js semantics: "process.nextTick and microtask queues
     *   are drained after every callback in the timers queue."
     *
     * 1. Pre-loop: drain nextTick & microtasks before doing anything else
     * 2. Determine UV run mode — NOWAIT when PHP-land work is already queued,
     *    RUN_ONCE to block until the next I/O or timer event otherwise
     * 3. Run libuv — wakes up when a timer fires or I/O is ready;
     *    stream and signal callbacks execute inside this call;
     *    timer master callback is intentionally a no-op, timers are pulled below
     * 4. Timer phase — collect ready callbacks and execute one at a time,
     *    draining nextTick + microtasks after each (Node.js timer phase semantics);
     *    reschedule master timer after consuming ready ones
     * 5. HTTP (cURL) phase
     * 6. Fiber phase — drain all ready fibers, draining ticks between each
     * 7. Check phase — setImmediate callbacks via queue swap (Node.js semantics)
     * 8. Close callbacks phase — deferred callbacks, only when all other work done
     *
     * NOTE: ticks + microtasks are drained after every phase transition
     */
    public function processWork(): bool
    {
        $workDone = false;

        if ($this->processTicksAndMicrotasks()) {
            $workDone = true;
        }

        $hasImmediateWork = $this->tickHandler->hasImmediateCallbacks()
            || $this->fiberManager->hasReadyFibers();

        $flags = $hasImmediateWork ? \UV::RUN_NOWAIT : \UV::RUN_ONCE;

        \uv_run($this->uvLoop, $flags);

        $timerCallbacks = $this->timerManager->collectReadyTimers();

        foreach ($timerCallbacks as $callback) {
            $callback();
            $workDone = true;
            $this->processTicksAndMicrotasks();
        }

        $this->timerManager->rescheduleMaster();

        if ($this->processTicksAndMicrotasks()) {
            $workDone = true;
        }

        if ($this->httpRequestManager->processRequests()) {
            $workDone = true;
            $this->processTicksAndMicrotasks();
        }

        while ($this->fiberManager->hasReadyFibers()) {
            if ($this->fiberManager->processFibers()) {
                $workDone = true;
                $this->processTicksAndMicrotasks();
            } else {
                break;
            }
        }

        if ($this->processCheckPhase()) {
            $workDone = true;
        }

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

        return true;
    }

    /**
     * Check phase: execute setImmediate callbacks using a queue swap so that
     * any setImmediate() calls made during this phase land in the next iteration,
     * preventing starvation of timers and I/O.
     */
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

    /**
     * Drain nextTick and microtask queues with correct priority.
     * nextTick always runs before microtasks.
     * Keeps looping until both queues are completely empty.
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
}
