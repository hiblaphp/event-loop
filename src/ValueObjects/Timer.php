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

    public int $executeAt; // Now in nanoseconds

    public function __construct(int $id, float $delay, callable $callback)
    {
        $this->id = $id;
        $this->callback = $callback;
        $hr = hrtime(true);
        $delayNs = (int)($delay * 1_000_000_000);
        $this->executeAt = $hr + $delayNs;
    }

    public function isReady(int $currentTimeNs): bool
    {
        return $currentTimeNs >= $this->executeAt;
    }

    public function execute(): void
    {
        ($this->callback)();
    }
}
