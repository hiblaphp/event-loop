<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Handlers;

use SplQueue;

final class TickHandler
{
    /**
     * @var SplQueue<callable>
     */
    private SplQueue $tickCallbacks;

    /**
     * @var SplQueue<callable>
     */
    private SplQueue $microtaskCallbacks;

    /**
     * @var SplQueue<callable>
     */
    private SplQueue $immediateCallbacks;

    /**
     * @var SplQueue<callable>
     */
    private SplQueue $deferredCallbacks;

    public function __construct()
    {
        $this->tickCallbacks = new SplQueue();
        $this->microtaskCallbacks = new SplQueue();
        $this->immediateCallbacks = new SplQueue();
        $this->deferredCallbacks = new SplQueue();
    }

    public function addNextTick(callable $callback): void
    {
        $this->tickCallbacks->enqueue($callback);
    }

    public function addMicrotask(callable $callback): void
    {
        $this->microtaskCallbacks->enqueue($callback);
    }

    public function addImmediate(callable $callback): void
    {
        $this->immediateCallbacks->enqueue($callback);
    }

    public function addDeferred(callable $callback): void
    {
        $this->deferredCallbacks->enqueue($callback);
    }

    public function processNextTickCallbacks(): bool
    {
        if ($this->tickCallbacks->isEmpty()) {
            return false;
        }

        $processed = false;
        $count = $this->tickCallbacks->count();

        for ($i = 0; $i < $count; $i++) {
            if ($this->tickCallbacks->isEmpty()) {
                break;
            }

            $callback = $this->tickCallbacks->dequeue();
            $callback();
            $processed = true;
        }

        return $processed;
    }

    public function processMicrotasks(): bool
    {
        if ($this->microtaskCallbacks->isEmpty()) {
            return false;
        }

        $processed = false;

        while (! $this->microtaskCallbacks->isEmpty()) {
            $callback = $this->microtaskCallbacks->dequeue();
            $callback();
            $processed = true;
        }

        return $processed;
    }

    public function processImmediateCallbacks(): bool
    {
        if ($this->immediateCallbacks->isEmpty()) {
            return false;
        }

        $processed = false;
        $count = $this->immediateCallbacks->count();

        for ($i = 0; $i < $count; $i++) {
            if ($this->immediateCallbacks->isEmpty()) {
                break;
            }

            $callback = $this->immediateCallbacks->dequeue();
            $callback();
            $processed = true;
        }

        return $processed;
    }

    public function processDeferredCallbacks(): bool
    {
        if ($this->deferredCallbacks->isEmpty()) {
            return false;
        }

        $processed = false;
        $count = $this->deferredCallbacks->count();

        for ($i = 0; $i < $count; $i++) {
            if ($this->deferredCallbacks->isEmpty()) {
                break;
            }

            $callback = $this->deferredCallbacks->dequeue();
            $callback();
            $processed = true;
        }

        return $processed;
    }

    public function clearAllCallbacks(): void
    {
        $this->tickCallbacks = new SplQueue();
        $this->microtaskCallbacks = new SplQueue();
        $this->immediateCallbacks = new SplQueue();
        $this->deferredCallbacks = new SplQueue();
    }

    public function hasTickCallbacks(): bool
    {
        return ! $this->tickCallbacks->isEmpty();
    }

    public function hasMicrotaskCallbacks(): bool
    {
        return ! $this->microtaskCallbacks->isEmpty();
    }

    public function hasImmediateCallbacks(): bool
    {
        return ! $this->immediateCallbacks->isEmpty();
    }

    public function hasDeferredCallbacks(): bool
    {
        return ! $this->deferredCallbacks->isEmpty();
    }

    public function hasWork(): bool
    {
        return ! $this->tickCallbacks->isEmpty()
            || ! $this->microtaskCallbacks->isEmpty()
            || ! $this->immediateCallbacks->isEmpty()
            || ! $this->deferredCallbacks->isEmpty();
    }

    /**
     * @return array<string, int>
     */
    public function getStats(): array
    {
        return [
            'tick_callbacks' => $this->tickCallbacks->count(),
            'microtask_callbacks' => $this->microtaskCallbacks->count(),
            'immediate_callbacks' => $this->immediateCallbacks->count(),
            'deferred_callbacks' => $this->deferredCallbacks->count(),
            'total_callbacks' => $this->tickCallbacks->count()
                + $this->microtaskCallbacks->count()
                + $this->immediateCallbacks->count()
                + $this->deferredCallbacks->count(),
        ];
    }
}
