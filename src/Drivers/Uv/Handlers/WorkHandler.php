<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Drivers\Uv\Handlers;

use Hibla\EventLoop\Handlers\TickHandler;
use Hibla\EventLoop\Interfaces\CurlRequestManagerInterface;
use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\Interfaces\SignalManagerInterface;
use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\Interfaces\UvTimerManagerInterface;
use Hibla\EventLoop\Interfaces\WorkHandlerInterface;

final class WorkHandler implements WorkHandlerInterface
{
    private \UVLoop $uvLoop;

    public function __construct(
        \UVLoop $uvLoop,
        private UvTimerManagerInterface $timerManager,
        private CurlRequestManagerInterface $curlRequestManager,
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
            || $this->curlRequestManager->hasRequests()
            || $this->streamManager->hasWatchers()
            || $this->fiberManager->hasFibers()
            || $this->signalManager->hasSignals();
    }

    /**
     * {@inheritDoc}
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
     * HTTP/curl phase works as follows:
     *   While requests are in flight, $hasImmediateWork is set to true, which
     *   forces RUN_NOWAIT so the event loop never blocks while curl needs
     *   servicing. curl_multi_exec() is called directly after uv_run() to
     *   drain any completions that landed during the iteration.
     *   NOTE: This trades the idle-sleep benefit of a dedicated curl service
     *   timer for simplicity — when HTTP requests are in flight the loop will
     *   busy-spin rather than sleeping between ticks.
     *
     * 1. Pre-loop: drain nextTick & microtasks before doing anything else
     * 2. Admit any newly queued HTTP requests into the curl multi handle
     * 3. Determine UV run mode — NOWAIT when PHP-land work (or active curl
     *    requests) is already queued, RUN_ONCE to block until the next I/O
     *    or timer event otherwise
     * 4. Run libuv — wakes up when a timer fires or I/O is ready;
     *    stream and signal callbacks execute inside this call;
     *    timer master callback is intentionally a no-op, timers are pulled below
     * 5. Timer phase — collect ready callbacks and execute one at a time,
     *    draining nextTick + microtasks after each (Node.js timer phase semantics);
     *    reschedule master timer after consuming ready ones
     * 6. HTTP (cURL) phase — service curl after uv_run to catch completions
     *    that landed during this iteration
     * 7. Fiber phase — drain all ready fibers, draining ticks between each
     * 8. Check phase — setImmediate callbacks via queue swap (Node.js semantics)
     * 9. Close callbacks phase — deferred callbacks, only when all other work done
     *
     * NOTE: ticks + microtasks are drained after every phase transition
     */
    public function processWork(bool $blocking = true): bool
    {
        $workDone = false;

        if ($this->processTicksAndMicrotasks()) {
            $workDone = true;
        }

        if ($this->curlRequestManager->processRequests()) {
            $workDone = true;
        }

        // Active curl requests force RUN_NOWAIT so the loop never blocks while
        // curl needs servicing. Without a dedicated curl service timer, this is
        // the mechanism that ensures curl_multi_exec() is called frequently
        // enough to notice completed transfers.
        $hasImmediateWork = $this->tickHandler->hasImmediateCallbacks()
            || $this->fiberManager->hasReadyFibers()
            || $this->curlRequestManager->hasRequests();

        $flags = (! $blocking || $hasImmediateWork) ? \UV::RUN_NOWAIT : \UV::RUN_ONCE;

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

        // Curl phase — service any completions that landed during uv_run,
        // draining ticks immediately after to keep phase ordering correct.
        if ($this->curlRequestManager->processRequests()) {
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
            || $this->curlRequestManager->hasRequests()
            || $this->streamManager->hasWatchers()
            || $this->fiberManager->hasFibers();

        if (! $hasPendingWork && $this->tickHandler->hasDeferredCallbacks()) {
            if ($this->tickHandler->processDeferredCallbacks()) {
                $workDone = true;
                $this->processTicksAndMicrotasks();
            }
        }

        return $workDone;
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
