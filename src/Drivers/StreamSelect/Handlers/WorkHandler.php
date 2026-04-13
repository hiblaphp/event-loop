<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Drivers\StreamSelect\Handlers;

use Hibla\EventLoop\Handlers\TickHandler;
use Hibla\EventLoop\Interfaces\CurlRequestManagerInterface;
use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\Interfaces\SignalManagerInterface;
use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\Interfaces\TimerManagerInterface;
use Hibla\EventLoop\Interfaces\WorkHandlerInterface;

final class WorkHandler implements WorkHandlerInterface
{
    /**
     * Never block stream_select at all when there is already work queued.
     * Equivalent to a pure poll — returns immediately regardless of I/O state.
     */
    private const int STREAM_TIMEOUT_IMMEDIATE_MICROSECONDS = 0;

    /**
     * Minimum stream_select block time.
     * Prevents the loop from becoming a busy-spin when a timer fires very soon.
     */
    private const int STREAM_TIMEOUT_MIN_MICROSECONDS = 0;

    /**
     * Used when no timers are pending and there is no immediate work.
     * The PHP manual recommends at least 200,000μs (200ms) for CPU efficiency.
     * stream_select() will still return early the moment I/O arrives.
     *
     * @see https://www.php.net/manual/en/function.stream-select.php
     */
    private const int STREAM_TIMEOUT_DEFAULT_MICROSECONDS = 200_000;

    /**
     * Microseconds per second — used to convert timer delays to microseconds.
     */
    private const int MICROSECONDS_PER_SECOND = 1_000_000;

    /**
     * Buffer factor applied to the next timer delay before passing it to
     * stream_select. Wakes up slightly before the timer fires to account
     * for scheduling jitter, matching the strategy used in SleepHandler.
     */
    private const float TIMER_BUFFER_FACTOR = 0.9;

    public function __construct(
        private TimerManagerInterface $timerManager,
        private CurlRequestManagerInterface $curlRequestManager,
        private StreamManagerInterface $streamManager,
        private FiberManagerInterface $fiberManager,
        private TickHandler $tickHandler,
        private SignalManagerInterface $signalManager,
    ) {
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
     * Process one full cycle of work following Node.js event loop semantics:
     * 1. Signal Handling
     * 2. NextTick callbacks (highest priority)
     * 3. Microtasks (drained completely)
     * 4. Timer Phase — process timers one at a time, drain ticks after each
     * 5. I/O Phase — streams, HTTP, file operations
     *    - stream_select timeout is driven by the next timer delay so the loop
     *      wakes up exactly when needed instead of burning cycles on a fixed interval
     *    - when HTTP is active but no stream watchers exist, usleep(10ms) on Unix
     *      (or a free-running loop on Windows) replaces stream_select to prevent
     *      a 100% CPU busy-spin while waiting for responses
     * 6. Fiber Phase — drain all ready fibers including ones that become ready
     *    mid-cycle via scheduleFiber() from resolved promises
     * 7. Check Phase — setImmediate() callbacks via queue swap (Node.js semantics)
     * 8. Close Callbacks Phase — deferred callbacks, only when all other work done
     *
     * NOTE: ticks + microtasks are drained after every phase transition
     */
    public function processWork(bool $blocking = true): bool
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

        $hasIO = $this->curlRequestManager->hasRequests()
            || $this->streamManager->hasWatchers();

        if ($hasIO) {
            if ($this->processIOOperations($blocking)) {
                $workDone = true;
                $this->processTicksAndMicrotasks();
            }
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

        // Signals are intentionally excluded from hasPendingWork — they are
        // edge-triggered and may never fire (e.g. SIGTERM on a normal exit).
        // CPU spinning is already prevented by SleepHandler which yields to
        // the OS when signals are the only remaining work source.
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
     * Process the Check phase (setImmediate callbacks).
     *
     * Uses a queue swap so that any setImmediate() calls made during this
     * phase land in a fresh queue and are deferred to the next event loop
     * iteration — matching Node.js check-phase semantics and preventing
     * check-phase starvation of timers and I/O.
     *
     * @return bool True if any work was processed
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
     * Keeps looping until both queues are completely empty, ensuring
     * proper draining semantics between every phase transition.
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
     * Process all pending I/O operations.
     *
     * Three cases handled:
     *
     *   1. Stream watchers exist — stream_select blocks for the calculated
     *      timeout (timer-aware) and curl is serviced in the same pass.
     *      stream_select wakes early the moment any stream becomes ready,
     *      so there is no fixed latency penalty.
     *
     *   2. HTTP requests active, no stream watchers — stream_select cannot
     *      be used with empty arrays. On Unix, usleep(CURL_POLL_INTERVAL)
     *      prevents a 100% CPU busy-spin while waiting for responses,
     *      clamped to the next timer deadline so timer accuracy is preserved.
     *      On Windows the sleep is skipped entirely (CURL_POLL_INTERVAL = 0)
     *      because usleep resolution is 15.6ms by default — SleepHandler's
     *      1ms Windows cap handles CPU yielding instead.
     *      The sleep is also skipped when blocking=false or when immediate
     *      work is already queued.
     *
     *   3. Non-blocking mode — curl is serviced once and returns immediately
     *      regardless of platform.
     *
     * @param bool $blocking Whether to allow sleeping while waiting for I/O
     * @return bool True if any I/O work was performed
     */
    private function processIOOperations(bool $blocking): bool
    {
        $workDone = false;

        if ($this->curlRequestManager->processRequests()) {
            $workDone = true;
        }

        if ($this->streamManager->hasWatchers()) {
            $timeout = $blocking ? $this->calculateStreamTimeout() : 0;

            if ($this->streamManager->processStreams($timeout)) {
                $workDone = true;
            }
        }

        return $workDone;
    }

    /**
     * Calculate the optimal stream_select timeout in microseconds.
     *
     * Priority:
     *   1. If immediate work is already queued → 0 (poll only, never block)
     *   2. If a timer is pending → 90% of its delay, clamped to MIN
     *   3. Otherwise → DEFAULT (200ms as recommended by PHP manual)
     *
     * This ensures prevent massive CPU usage by blocking stream_select
     * for longer than necessary when there is immediate work pending.
     *
     * @return int Timeout in microseconds
     */
    private function calculateStreamTimeout(): int
    {
        $hasImmediateWork = $this->tickHandler->hasTickCallbacks()
            || $this->tickHandler->hasMicrotaskCallbacks()
            || $this->tickHandler->hasImmediateCallbacks()
            || $this->fiberManager->hasReadyFibers();

        if ($hasImmediateWork) {
            return self::STREAM_TIMEOUT_IMMEDIATE_MICROSECONDS;
        }

        $nextDelay = $this->timerManager->getNextTimerDelay();

        if ($nextDelay === null) {
            // No timers pending — block up to DEFAULT.
            // stream_select() returns early the moment I/O arrives so this
            // does not introduce latency, only reduces unnecessary wake-ups.
            return self::STREAM_TIMEOUT_DEFAULT_MICROSECONDS;
        }

        // Convert next timer delay to microseconds and apply buffer factor
        // so stream_select wakes up slightly before the timer fires,
        // preventing late execution due to I/O blocking.
        $delayUs = (int) ($nextDelay * self::MICROSECONDS_PER_SECOND * self::TIMER_BUFFER_FACTOR);

        return max($delayUs, self::STREAM_TIMEOUT_MIN_MICROSECONDS);
    }
}
