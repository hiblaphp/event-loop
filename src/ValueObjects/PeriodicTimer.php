<?php

declare(strict_types=1);

namespace Hibla\EventLoop\ValueObjects;

final class PeriodicTimer
{
    public int $id;

    /**
     * @var callable
     */
    public $callback;

    public float $executeAt;

    public float $interval;

    public ?int $maxExecutions;
    
    public int $executionCount = 0;

    public function __construct(int $id, float $interval, callable $callback, ?int $maxExecutions = null)
    {
        $this->id = $id;
        $this->callback = $callback;
        $this->interval = $interval;
        $this->maxExecutions = $maxExecutions;
        $this->executeAt = microtime(true) + $interval;
    }

    public function isReady(float $currentTime): bool
    {
        return $currentTime >= $this->executeAt;
    }

    public function execute(): void
    {
        $this->executionCount++;
        ($this->callback)();

        if ($this->shouldContinue()) {
            $this->executeAt = microtime(true) + $this->interval;
        }
    }

    public function shouldContinue(): bool
    {
        return $this->maxExecutions === null || $this->executionCount < $this->maxExecutions;
    }
}