<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use Fiber;
use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\IOHandlers\Fiber\FiberResumeHandler;
use Hibla\EventLoop\IOHandlers\Fiber\FiberStartHandler;
use Hibla\EventLoop\IOHandlers\Fiber\FiberStateHandler;


final class FiberManager implements FiberManagerInterface
{
    /** 
     * @var array<int, Fiber<mixed, mixed, mixed, mixed>> 
    */
    private array $fibers = [];

    /** 
     * @var array<int, Fiber<mixed, mixed, mixed, mixed>> 
    */
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
     * @param  Fiber<null, mixed, mixed, mixed>  $fiber  The fiber to add.
     */
    public function addFiber(Fiber $fiber): void
    {
        if (! ($this->acceptingNewFibers ?? true)) {
            return;
        }

        $this->fibers[] = $fiber;
    }

    public function processFibers(): bool
    {
        if (\count($this->fibers) === 0 && \count($this->suspendedFibers) === 0) {
            return false;
        }

        $processed = false;

        // Prioritize starting new fibers first.
        if (\count($this->fibers) > 0) {
            $processed = $this->processNewFibers();
        } elseif (\count($this->suspendedFibers) > 0) {
            $processed = $this->processSuspendedFibers();
        }

        return $processed;
    }

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

    public function hasFibers(): bool
    {
        return \count($this->fibers) > 0 || \count($this->suspendedFibers) > 0;
    }

    public function hasActiveFibers(): bool
    {
        return $this->stateHandler->hasActiveFibers($this->suspendedFibers) || count($this->fibers) > 0;
    }

    public function clearFibers(): void
    {
        $this->fibers = [];
        $this->suspendedFibers = [];
    }

    public function prepareForShutdown(): void
    {
        $this->acceptingNewFibers = false;
    }

    public function isAcceptingNewFibers(): bool
    {
        return $this->acceptingNewFibers ?? true;
    }
}
