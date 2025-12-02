<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Handlers;

final class TickHandler
{
    /**
     * @var list<callable>
     */
    private array $tickCallbacks = [];

    /**
     * @var list<callable> 
     */
    private array $deferredCallbacks = [];

    private const int BATCH_SIZE = 100;

    public function addNextTick(callable $callback): void
    {
        $this->tickCallbacks[] = $callback;
    }

    public function addDeferred(callable $callback): void
    {
        $this->deferredCallbacks[] = $callback;
    }

    public function processNextTickCallbacks(): bool
    {
        if ($this->tickCallbacks === []) {
            return false;
        }

        return $this->processBatch($this->tickCallbacks, 'NextTick');
    }

    public function processDeferredCallbacks(): bool
    {
        if ($this->deferredCallbacks === []) {
            return false;
        }

        $callbacks = $this->deferredCallbacks;
        $this->deferredCallbacks = [];

        return $this->executeBatch($callbacks, 'Deferred');
    }

    public function clearAllCallbacks(): void
    {
        $this->tickCallbacks = [];
        $this->deferredCallbacks = [];
    }

    /**
     * @param  list<callable>  $callbacks 
     * @param  string  $type  
     * @return bool 
     */
    private function processBatch(array &$callbacks, string $type): bool
    {
        $batchSize = min(self::BATCH_SIZE, \count($callbacks));
        $batch = array_splice($callbacks, 0, $batchSize);

        return $this->executeBatch($batch, $type);
    }

    /**
     * @param  list<callable>  $callbacks
     * @return bool
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

    public function hasTickCallbacks(): bool
    {
        return $this->tickCallbacks !== [];
    }

    public function hasDeferredCallbacks(): bool
    {
        return $this->deferredCallbacks !== [];
    }
}
