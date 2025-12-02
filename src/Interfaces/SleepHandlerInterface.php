<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Interfaces;

interface SleepHandlerInterface
{
    public function shouldSleep(bool $hasImmediateWork): bool;

    public function sleep(int $microseconds): void;

    public function calculateOptimalSleep(): int;
}
