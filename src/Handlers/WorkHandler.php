<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Handlers;

use Hibla\EventLoop\Managers\FiberManager;
use Hibla\EventLoop\Managers\FileManager;
use Hibla\EventLoop\Managers\HttpRequestManager;
use Hibla\EventLoop\Managers\SignalManager;
use Hibla\EventLoop\Managers\StreamManager;
use Hibla\EventLoop\Managers\TimerManager;

/**
 * Orchestrates all units of work in the event loop:
 * - Next-tick and deferred callbacks
 * - Timers and fibers
 * - HTTP requests, sockets, streams, and file operations
 */
class WorkHandler
{
    public function __construct(
        protected TimerManager $timerManager,
        protected HttpRequestManager $httpRequestManager,
        protected StreamManager $streamManager,
        protected FiberManager $fiberManager,
        protected TickHandler $tickHandler,
        protected FileManager $fileManager,
        protected SignalManager $signalManager,
    ) {
    }

    /**
     * Determine if there is any pending work in the loop.
     *
     * Checks callbacks, timers, HTTP requests, file I/O, streams, sockets, and fibers.
     *
     * @return bool True if any work units are pending.
     */
    public function hasWork(): bool
    {
        return $this->tickHandler->hasTickCallbacks()
            || $this->tickHandler->hasDeferredCallbacks()
            || $this->timerManager->hasTimers()
            || $this->httpRequestManager->hasRequests()
            || $this->fileManager->hasWork()
            || $this->streamManager->hasWatchers()
            || $this->fiberManager->hasFibers()
            || $this->signalManager->hasSignals();
    }

    /**
     * Process one full cycle of work:
     * 1. Signal Handling
     * 2. Next-tick callbacks
     * 3. Timers and fibers
     * 4. I/O operations (HTTP, sockets, streams, files)
     * 5. Deferred callbacks
     *
     * @return bool True if any work was performed.
     */
    public function processWork(): bool
    {
        $workDone = false;

        if ($this->signalManager->processSignals()) {
            $workDone = true;
        } 
        
        if ($this->tickHandler->processNextTickCallbacks()) {
            $workDone = true;
        }

        $timerWork = $this->timerManager->processTimers();
        $fiberWork = $this->fiberManager->processFibers();

        if ($timerWork || $fiberWork) {
            $workDone = true;
        }


        if ($this->processIOOperations()) {
            $workDone = true;
        }

        if ($this->tickHandler->processDeferredCallbacks()) {
            $workDone = true;
        }

        return $workDone;
    }

    /**
     * Process all types of I/O in a single batch:
     * - HTTP requests
     * - Streams (only if watchers exist)
     * - File operations
     *
     * @return bool True if any I/O work was performed.
     */
    protected function processIOOperations(): bool
    {
        $workDone = false;

        if ($this->httpRequestManager->processRequests()) {
            $workDone = true;
        }

        if ($this->streamManager->hasWatchers()) {
            $this->streamManager->processStreams();
            $workDone = true;
        }

        if ($this->fileManager->processFileOperations()) {
            $workDone = true;
        }

        return $workDone;
    }
}
