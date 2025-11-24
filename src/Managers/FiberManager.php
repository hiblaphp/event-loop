<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use Fiber;
use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\IOHandlers\Fiber\FiberResumeHandler;
use Hibla\EventLoop\IOHandlers\Fiber\FiberStartHandler;
use Hibla\EventLoop\IOHandlers\Fiber\FiberStateHandler;

/**
 * Manages the lifecycle of all fibers within the event loop.
 *
 * This class is responsible for queuing new fibers, processing them,
 * and managing the state of suspended fibers, ensuring they are resumed
 * when appropriate.
 */
final class FiberManager implements FiberManagerInterface
{
    /** @var array<int, Fiber<mixed, mixed, mixed, mixed>> */
    private array $fibers = [];
    /** @var array<int, Fiber<mixed, mixed, mixed, mixed>> */
    private array $suspendedFibers = [];
    private bool $acceptingNewFibers = true;

    private readonly FiberStartHandler $startHandler;
    private readonly FiberResumeHandler $resumeHandler;
    private readonly FiberStateHandler $stateHandler;

    public function __construct()
    {
        $this->startHandler = new FiberStartHandler();
        $this->resumeHandler = new FiberResumeHandler();
        $this->stateHandler = new FiberStateHandler();
    }

    /**
     * Adds a new, unstarted fiber to the processing queue.
     *
     * @param  Fiber<null, mixed, mixed, mixed>  $fiber  The fiber to add.
     */
    public function addFiber(Fiber $fiber): void
    {
        if (! ($this->acceptingNewFibers ?? true)) {
            return;
        }

        $this->fibers[] = $fiber;
    }

    /**
     * Processes one batch of new or suspended fibers.
     *
     * Prioritizes starting new fibers before resuming suspended ones.
     * This method should be called on each tick of the event loop.
     *
     * @return bool True if any fiber was processed (started or resumed), false otherwise.
     */
    public function processFibers(): bool
    {
        if (count($this->fibers) === 0 && count($this->suspendedFibers) === 0) {
            return false;
        }

        $processed = false;

        // Prioritize starting new fibers first.
        if (count($this->fibers) > 0) {
            $processed = $this->processNewFibers();
        } elseif (count($this->suspendedFibers) > 0) {
            $processed = $this->processSuspendedFibers();
        }

        return $processed;
    }

    /**
     * Starts all fibers currently in the new-fiber queue.
     *
     * Moves started fibers that are now suspended to the suspended queue.
     *
     * @return bool True if at least one fiber was successfully started.
     */
    private function processNewFibers(): bool
    {
        $fibersToStart = $this->fibers;
        $this->fibers = [];
        $processed = false;

        foreach ($fibersToStart as $fiber) {
            if ($this->startHandler->canStart($fiber)) {
                if ($this->startHandler->startFiber($fiber)) {
                    $processed = true;
                }

                if ($fiber->isSuspended()) {
                    $this->suspendedFibers[] = $fiber;
                }
            }
        }

        return $processed;
    }

    /**
     * Resumes all fibers currently in the suspended-fiber queue.
     *
     * Moves fibers that remain suspended after being resumed back into the queue
     * for the next processing tick.
     *
     * @return bool True if at least one fiber was successfully resumed.
     */
    private function processSuspendedFibers(): bool
    {
        $fibersToResume = $this->suspendedFibers;
        $this->suspendedFibers = [];
        $processed = false;

        foreach ($fibersToResume as $fiber) {
            if ($this->resumeHandler->canResume($fiber)) {
                if ($this->resumeHandler->resumeFiber($fiber)) {
                    $processed = true;
                }

                if ($fiber->isSuspended()) {
                    $this->suspendedFibers[] = $fiber;
                }
            }
        }

        return $processed;
    }

    /**
     * Checks if there are any fibers (new or suspended) pending.
     *
     * @return bool True if there are fibers in any queue.
     */
    public function hasFibers(): bool
    {
        return count($this->fibers) > 0 || count($this->suspendedFibers) > 0;
    }

    /**
     * Checks if there are any fibers that can be actively processed.
     *
     * This includes new, unstarted fibers and suspended fibers that are not
     * yet terminated.
     *
     * @return bool True if there are active fibers.
     */
    public function hasActiveFibers(): bool
    {
        return $this->stateHandler->hasActiveFibers($this->suspendedFibers) || count($this->fibers) > 0;
    }

    public function clearFibers(): void
    {
        $this->fibers = [];
        $this->suspendedFibers = [];
    }

    /**
     * Attempt graceful fiber cleanup.
     * Used during shutdown - allows fibers to complete naturally.
     */
    public function prepareForShutdown(): void
    {
        $this->acceptingNewFibers = false;
    }

    /**
     * Check if we're accepting new fibers (for shutdown state)
     */
    public function isAcceptingNewFibers(): bool
    {
        return $this->acceptingNewFibers ?? true;
    }
}
