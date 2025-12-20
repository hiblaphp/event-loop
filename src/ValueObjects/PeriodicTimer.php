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

    public int $executeAt;

    public int $intervalNs;

    public ?int $maxExecutions;

    public int $executionCount = 0;

    public function __construct(int $id, float $interval, callable $callback, ?int $maxExecutions = null)
    {
        $this->id = $id;
        $this->callback = $callback;
        $this->intervalNs = (int)($interval * 1_000_000_000);
        $this->maxExecutions = $maxExecutions;
        $this->executeAt = hrtime(true) + $this->intervalNs;
    }

    public function isReady(int $currentTimeNs): bool
    {
        return $currentTimeNs >= $this->executeAt;
    }

    public function execute(): void
    {
        $this->executionCount++;
        ($this->callback)();

        if ($this->shouldContinue()) {
            $this->executeAt += $this->intervalNs;
        }
    }

    public function shouldContinue(): bool
    {
        return $this->maxExecutions === null || $this->executionCount < $this->maxExecutions;
    }
}