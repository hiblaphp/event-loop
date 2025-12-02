<?php

declare(strict_types=1);

namespace Hibla\EventLoop\ValueObjects;

final class PeriodicTimer
{
    private string $id;

    /**
     * @var callable
     */
    private $callback;

    private float $interval;

    private float $executeAt;

    private ?int $maxExecutions;
    
    private int $executionCount = 0;

    public function __construct(float $interval, callable $callback, ?int $maxExecutions = null)
    {
        $this->id = uniqid('periodic_timer_', true);
        $this->callback = $callback;
        $this->interval = $interval;
        $this->maxExecutions = $maxExecutions;
        $this->executeAt = microtime(true) + $interval;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCallback(): callable
    {
        return $this->callback;
    }

    public function getExecuteAt(): float
    {
        return $this->executeAt;
    }

    public function getInterval(): float
    {
        return $this->interval;
    }

    public function isReady(float $currentTime): bool
    {
        return $currentTime >= $this->executeAt;
    }

    public function execute(): void
    {
        $this->executionCount++;
        ($this->callback)();

        // Schedule next execution if we should continue
        if ($this->shouldContinue()) {
            $this->executeAt = microtime(true) + $this->interval;
        }
    }

    public function shouldContinue(): bool
    {
        return $this->maxExecutions === null || $this->executionCount < $this->maxExecutions;
    }

    public function getExecutionCount(): int
    {
        return $this->executionCount;
    }

    public function getRemainingExecutions(): ?int
    {
        if ($this->maxExecutions === null) {
            return null;
        }

        return max(0, $this->maxExecutions - $this->executionCount);
    }

    public function isPeriodic(): bool
    {
        return true;
    }
}
