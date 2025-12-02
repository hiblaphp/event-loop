<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Interfaces;

interface WorkHandlerInterface
{
    public function processWork(): bool;

    public function hasWork(): bool;
}
