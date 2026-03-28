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

    private \UVTimer $curlTimer;

    /**
     * Tracks whether the curl service timer is currently armed.
     * Used instead of uv_is_active() which is not reliable across
     * all ext-uv builds.
     */
    private bool $curlTimerActive = false;

    /**
     * How often (in milliseconds) the curl service timer fires while
     * HTTP requests are in flight.
     *
     * 10ms is a balance between responsiveness and CPU cost:
     * - Low enough that responses are noticed quickly
     * - High enough that libuv genuinely sleeps between ticks via RUN_ONCE
     *
     * libuv will also wake earlier than this if any other watcher fires
     * (app timers, signals, streams) so this is a ceiling, not a fixed cost.
     */
    private const int CURL_TIMER_INTERVAL_MS = 10;

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
        $this->curlTimer = uv_timer_init($this->uvLoop);
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
     *   A dedicated repeating uv_timer fires every CURL_TIMER_INTERVAL_MS
     *   while requests are in flight. This means libuv can genuinely sleep
     *   via RUN_ONCE between ticks — curl is serviced by the timer waking
     *   libuv, not by forcing RUN_NOWAIT on every iteration.
     *   The timer is armed only when requests are active and disarmed the
     *   moment the last request completes, so there is zero overhead at idle.
     *
     * 1. Pre-loop: drain nextTick & microtasks before doing anything else
     * 2. Admit any newly queued HTTP requests into the curl multi handle,
     *    and arm the curl service timer if not already running
     * 3. Determine UV run mode — NOWAIT when PHP-land work is already queued,
     *    RUN_ONCE to block until the next I/O or timer event otherwise
     * 4. Run libuv — wakes up when a timer fires or I/O is ready;
     *    stream and signal callbacks execute inside this call;
     *    timer master callback is intentionally a no-op, timers are pulled below
     * 5. Timer phase — collect ready callbacks and execute one at a time,
     *    draining nextTick + microtasks after each (Node.js timer phase semantics);
     *    reschedule master timer after consuming ready ones
     * 6. HTTP (cURL) phase — service curl after uv_run in case the curl
     *    service timer fired during this iteration
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

        // Arm the curl service timer while requests are in flight.
        // The timer fires every CURL_TIMER_INTERVAL_MS, calling curl_multi_exec
        // which is non-blocking. This wakes libuv on schedule so RUN_ONCE can
        // sleep instead of busy-spinning via RUN_NOWAIT.
        $this->syncCurlTimer();

        $hasImmediateWork = $this->tickHandler->hasImmediateCallbacks()
            || $this->fiberManager->hasReadyFibers();

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

        // The curl service timer fires inside uv_run, but processRequests()
        // here catches completions that landed in this same tick and drains
        // ticks immediately after, keeping phase ordering correct.
        if ($this->curlRequestManager->hasRequests()) {
            if ($this->curlRequestManager->processRequests()) {
                $workDone = true;
                $this->processTicksAndMicrotasks();
            }

            // Re-sync the timer now that requests may have completed.
            $this->syncCurlTimer();
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
     * Arm or disarm the curl service timer based on whether HTTP requests
     * are currently active.
     *
     * - Active requests   → start the timer if not already running
     * - No active requests → stop the timer to eliminate idle overhead
     *
     * The timer calls processRequests() on each tick, which internally calls
     * curl_multi_exec() — a non-blocking call that returns immediately if
     * nothing has happened. The repeat interval ensures libuv wakes from
     * RUN_ONCE at most every CURL_TIMER_INTERVAL_MS while HTTP is in flight,
     * giving curl regular opportunities to notice completed transfers without
     * the event loop busy-spinning.
     */
    private function syncCurlTimer(): void
    {
        $hasRequests = $this->curlRequestManager->hasRequests();

        if ($hasRequests && ! $this->curlTimerActive) {
            uv_timer_start(
                $this->curlTimer,
                self::CURL_TIMER_INTERVAL_MS,
                self::CURL_TIMER_INTERVAL_MS,
                function (): void {
                    $this->curlRequestManager->processRequests();

                    if (! $this->curlRequestManager->hasRequests()) {
                        uv_timer_stop($this->curlTimer);
                        $this->curlTimerActive = false;
                    }
                },
            );

            $this->curlTimerActive = true;

            return;
        }

        if (! $hasRequests && $this->curlTimerActive) {
            uv_timer_stop($this->curlTimer);
            $this->curlTimerActive = false;
        }
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
