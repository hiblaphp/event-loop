<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Drivers\Uv\Managers;

use Hibla\EventLoop\Interfaces\SignalManagerInterface;
use Hibla\EventLoop\ValueObjects\Signal;

final class SignalManager implements SignalManagerInterface
{
    /**
     * @var resource 
     */
    private $uvLoop;

    /**
     * @var array<int, resource> Map of signal number to uv_signal resource
     */
    private array $uvHandles = [];

    /**
     * @var array<int, array<string, Signal>>
     */
    private array $signals = [];
    /**
     * @var array<string, int> Map of signalId to signal number for fast removal
     */
    private array $signalIndex = [];

    /**
     * Shared callback for all UV signal events
     */
    private \Closure $signalCallback;

    public function __construct($uvLoop)
    {
        $this->uvLoop = $uvLoop;

        // Shared callback that LibUV triggers when a signal is caught
        $this->signalCallback = function ($handle, $signalNum) {
            if (!empty($this->signals[$signalNum])) {
                foreach ($this->signals[$signalNum] as $signalObject) {
                    $signalObject->invoke($signalNum);
                }
            }
        };
    }

    /**
     * {@inheritDoc}
     */
    public function addSignal(int $signal, callable $callback): string
    {
        // Generate a unique ID and value object for this specific listener
        $id = uniqid('signal_', true);
        $signalObject = new Signal($signal, $callback, $id);

        $this->signals[$signal][$id] = $signalObject;
        $this->signalIndex[$id] = $signal;

        // If this is the first listener for this specific signal, create the UV handle
        if (!isset($this->uvHandles[$signal])) {
            $handle = \uv_signal_init($this->uvLoop);
            $this->uvHandles[$signal] = $handle;

            \uv_signal_start($handle, $this->signalCallback, $signal);
        }

        return $id;
    }

    /**
     * {@inheritDoc}
     */
    public function removeSignal(string $signalId): bool
    {
        if (!isset($this->signalIndex[$signalId])) {
            return false;
        }

        $signalNum = $this->signalIndex[$signalId];

        // Remove the specific listener
        unset($this->signals[$signalNum][$signalId]);
        unset($this->signalIndex[$signalId]);

        // If no more listeners exist for this signal, stop and clean up the UV handle
        if (empty($this->signals[$signalNum])) {
            unset($this->signals[$signalNum]);

            if (isset($this->uvHandles[$signalNum])) {
                $handle = $this->uvHandles[$signalNum];
                @\uv_signal_stop($handle);
                \uv_close($handle);
                unset($this->uvHandles[$signalNum]);
            }
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function hasSignals(): bool
    {
        return \count($this->signals) > 0;
    }

    /**
     * {@inheritDoc}
     * No-op: libuv handles signal processing natively inside uv_run
     */
    public function processSignals(): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function clearAllSignals(): void
    {
        foreach ($this->uvHandles as $handle) {
            @\uv_signal_stop($handle);
            \uv_close($handle);
        }

        $this->uvHandles = [];
        $this->signals = [];
        $this->signalIndex = [];
    }

    /**
     * {@inheritDoc}
     */
    public function getListenerCount(int $signal): int
    {
        return isset($this->signals[$signal]) ? \count($this->signals[$signal]) : 0;
    }
}
