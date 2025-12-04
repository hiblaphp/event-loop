<?php

declare(strict_types=1);

namespace Hibla\EventLoop\ValueObjects;

final class Timer
{
    public int $id;

    /**
     * @var callable
     */
    public $callback;
    
    public float $executeAt;

    public function __construct(int $id, float $delay, callable $callback)
    {
        $this->id = $id;
        $this->callback = $callback;
        $this->executeAt = microtime(true) + $delay;
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

