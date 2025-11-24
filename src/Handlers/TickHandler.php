<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Handlers;

/**
 * Handles scheduling of tick and deferred callbacks in batches.
 */
final class TickHandler
{
    /**
     * @var list<callable> Callbacks to run on the next tick.
     */
    private array $tickCallbacks = [];

    /**
     * @var list<callable> Callbacks to run after current tick (deferred).
     */
    private array $deferredCallbacks = [];

    /**
     * Maximum number of callbacks to execute in one batch.
     */
    private const BATCH_SIZE = 100;

    /**
     * Schedule a callback to run on the next loop tick.
     */
    public function addNextTick(callable $callback): void
    {
        $this->tickCallbacks[] = $callback;
    }

    /**
     * Schedule a callback to run after current tick (deferred).
     */
    public function addDeferred(callable $callback): void
    {
        $this->deferredCallbacks[] = $callback;
    }

    /**
     * Process up to BATCH_SIZE next-tick callbacks.
     *
     * @return bool True if any callbacks were processed.
     */
    public function processNextTickCallbacks(): bool
    {
        if ($this->tickCallbacks === []) {
            return false;
        }

        return $this->processBatch($this->tickCallbacks, 'NextTick');
    }

    /**
     * Process all deferred callbacks in one go.
     *
     * @return bool True if any callbacks were processed.
     */
    public function processDeferredCallbacks(): bool
    {
        if ($this->deferredCallbacks === []) {
            return false;
        }

        $callbacks = $this->deferredCallbacks;
        $this->deferredCallbacks = [];

        return $this->executeBatch($callbacks, 'Deferred');
    }

    /**
     * Clear all pending tick and deferred callbacks.
     * Used during forced shutdown to prevent hanging.
     */
    public function clearAllCallbacks(): void
    {
        $this->tickCallbacks = [];
        $this->deferredCallbacks = [];
    }

    /**
     * Split the callback list into a batch and execute it.
     *
     * @param  list<callable>  $callbacks  Passed by reference; batch is spliced off.
     * @param  string  $type  Label for error logging.
     * @return bool True if any callbacks were executed.
     */
    private function processBatch(array &$callbacks, string $type): bool
    {
        $batchSize = min(self::BATCH_SIZE, count($callbacks));
        $batch = array_splice($callbacks, 0, $batchSize);

        return $this->executeBatch($batch, $type);
    }

    /**
     * Execute a batch of callbacks, catching any exceptions.
     *
     * @param  list<callable>  $callbacks
     * @return bool True if at least one callback ran successfully.
     */
    private function executeBatch(array $callbacks, string $type): bool
    {
        $processed = false;

        foreach ($callbacks as $callback) {
            try {
                $callback();
                $processed = true;
            } catch (\Throwable $e) {
                error_log("{$type} callback error: ".$e->getMessage());
            }
        }

        return $processed;
    }

    /**
     * Check if there are any pending next-tick callbacks.
     */
    public function hasTickCallbacks(): bool
    {
        return $this->tickCallbacks !== [];
    }

    /**
     * Check if there are any pending deferred callbacks.
     */
    public function hasDeferredCallbacks(): bool
    {
        return $this->deferredCallbacks !== [];
    }
}
