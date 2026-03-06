<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Interfaces;

interface WorkHandlerInterface
{
    /**
     * Process one full cycle of the event loop.
     *
     * This method orchestrates the execution of all event loop phases,
     * including timers, I/O, fibers, and various task queues (nextTick, microtasks, check).
     *
     * @param bool $blocking If true, the loop will block the thread waiting for
     *                       I/O events if no immediate tasks are pending (e.g., via
     *                       stream_select or uv_run). If false, the loop will
     *                       perform a non-blocking poll (e.g., UV::RUN_NOWAIT)
     *                       to process existing work and return immediately.
     *
     * @return bool Returns true if any work was performed during this cycle,
     *              false if the loop was idle.
     */
    public function processWork(bool $blocking = true): bool;

    /**
     * Checks if there is any pending work that needs to be processed by the loop.
     *
     * This considers all registered handlers including timers, streams,
     * signals, fibers, and task queues.
     *
     * @return bool True if there is work to process, false otherwise.
     */
    public function hasWork(): bool;
}
