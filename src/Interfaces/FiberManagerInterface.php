<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Interfaces;

use Fiber;

/**
 * Interface for managing fiber lifecycle within the event loop.
 */
interface FiberManagerInterface
{
    /**
     * Adds a new, unstarted fiber to the processing queue.
     *
     * @param  Fiber<null, mixed, mixed, mixed>  $fiber  The fiber to add
     */
    public function addFiber(Fiber $fiber): void;

    /**
     * Schedules a fiber for processing, moving it from the ready queue to the active queue.
     *
     * @param  Fiber<null, mixed, mixed, mixed>  $fiber  The fiber to schedule
     */
    public function scheduleFiber(Fiber $fiber): void;

    /**
     * Processes one batch of new or suspended fibers.
     *
     * @return bool True if any fiber was processed, false otherwise
     */
    public function processFibers(): bool;

    /**
     * Checks if there are any fibers pending processing.
     *
     * @return bool True if there are fibers in any queue
     */
    public function hasFibers(): bool;

    /**
     * Checks if there are any fibers that can be actively processed.
     *
     * @return bool True if there are active fibers
     */
    public function hasActiveFibers(): bool;

    /**
     * Clears all fibers from queues.
     */
    public function clearFibers(): void;

    /**
     * Prepares for shutdown by stopping acceptance of new fibers.
     */
    public function prepareForShutdown(): void;

    /**
     * Checks if new fibers are being accepted.
     *
     * @return bool True if accepting new fibers
     */
    public function isAcceptingNewFibers(): bool;
}
