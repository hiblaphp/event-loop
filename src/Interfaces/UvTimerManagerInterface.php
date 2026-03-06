<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Interfaces;

/**
 * Extends TimerManagerInterface with UV-specific collection semantics.
 *
 * The UV driver cannot execute timer callbacks inside uv_run() and still
 * maintain correct nextTick/microtask interleaving. Instead, WorkHandler
 * pulls ready callbacks via collectReadyTimers() and executes them one at
 * a time with microtask draining between each — matching Node.js semantics.
 */
interface UvTimerManagerInterface extends TimerManagerInterface
{
    /**
     * Collect all ready timer callbacks without executing them.
     *
     * Returns closures so WorkHandler can execute them individually
     * with nextTick and microtask queues drained between each callback.
     *
     * @return list<callable>
     */
    public function collectReadyTimers(): array;

    /**
     * Reschedule the master libuv timer handle for the next pending timer.
     *
     * Called by WorkHandler after it has finished executing collected
     * timer callbacks, so libuv knows when to wake up next.
     */
    public function rescheduleMaster(): void;
}
