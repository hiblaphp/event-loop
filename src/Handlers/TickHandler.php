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
    private SplQueue $deferredCallbacks;

    public function __construct()
    {
        $this->tickCallbacks = new SplQueue();
        $this->microtaskCallbacks = new SplQueue();
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

            try {
                $callback();
                $processed = true;
            } catch (\Throwable $e) {
                error_log("NextTick callback error: " . $e->getMessage());
            }
        }

        return $processed;
    }

    public function processMicrotasks(int $maxIterations = 10000): bool
    {
        if ($this->microtaskCallbacks->isEmpty()) {
            return false;
        }

        $processed = false;
        $iterations = 0;

        while (!$this->microtaskCallbacks->isEmpty() && $iterations < $maxIterations) {
            $callback = $this->microtaskCallbacks->dequeue();

            try {
                $callback();
                $processed = true;
                $iterations++;
            } catch (\Throwable $e) {
                error_log("Microtask callback error: " . $e->getMessage());
            }
        }

        if ($iterations >= $maxIterations && !$this->microtaskCallbacks->isEmpty()) {
            error_log("Warning: Microtask queue exceeded max iterations ($maxIterations). Possible infinite loop detected.");
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
            
            try {
                $callback();
                $processed = true;
            } catch (\Throwable $e) {
                error_log("Deferred callback error: " . $e->getMessage());
            }
        }

        return $processed;
    }

    public function clearAllCallbacks(): void
    {
        $this->tickCallbacks = new SplQueue();
        $this->microtaskCallbacks = new SplQueue();
        $this->deferredCallbacks = new SplQueue();
    }

    public function hasTickCallbacks(): bool
    {
        return !$this->tickCallbacks->isEmpty();
    }

    public function hasMicrotaskCallbacks(): bool
    {
        return !$this->microtaskCallbacks->isEmpty();
    }

    public function hasDeferredCallbacks(): bool
    {
        return !$this->deferredCallbacks->isEmpty();
    }

    public function hasWork(): bool
    {
        return !$this->tickCallbacks->isEmpty()
            || !$this->microtaskCallbacks->isEmpty()
            || !$this->deferredCallbacks->isEmpty();
    }

    /**
     * @return array<string, int>
     */
    public function getStats(): array
    {
        return [
            'tick_callbacks' => $this->tickCallbacks->count(),
            'microtask_callbacks' => $this->microtaskCallbacks->count(),
            'deferred_callbacks' => $this->deferredCallbacks->count(),
            'total_callbacks' => $this->tickCallbacks->count() 
                + $this->microtaskCallbacks->count() 
                + $this->deferredCallbacks->count(),
        ];
    }
}