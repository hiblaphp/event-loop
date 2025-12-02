<?php

declare(strict_types=1);

namespace Hibla\EventLoop\ValueObjects;

final class Timer
{
    private string $id;

    /**
     * @var callable
     */
    private $callback;

    private float $executeAt;

    public function __construct(float $delay, callable $callback)
    {
        $this->id = uniqid('timer_', true);
        $this->callback = $callback;
        $this->executeAt = microtime(true) + $delay;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getExecuteAt(): float
    {
        return $this->executeAt;
    }

    public function isReady(float $currentTime): bool
    {
        return $currentTime >= $this->executeAt;
    }

    public function execute(): void
    {
        ($this->callback)();
    }
}
