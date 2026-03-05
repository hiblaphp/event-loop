<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Drivers\Uv\Managers;

use Hibla\EventLoop\Interfaces\SignalManagerInterface;

final class SignalManager implements SignalManagerInterface
{
    /**
     *  @var resource 
     */
    private $uvLoop;

    public function __construct($uvLoop)
    {
        $this->uvLoop = $uvLoop;
    }

    public function addSignal(int $signal, callable $callback): string
    {
        // TODO: Implement uv_signal_init
        return uniqid('uv_sig_', true);
    }

    public function removeSignal(string $signalId): bool
    {
        return false;
    }

    public function hasSignals(): bool
    {
        return false;
    }

    public function processSignals(): bool
    {
        // No-op: uv_run handles this
        return false;
    }

    public function clearAllSignals(): void
    {
        // TODO: Cleanup handles
    }

    public function getListenerCount(int $signal): int
    {
        return 0;
    }
}