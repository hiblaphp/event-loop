<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use Hibla\EventLoop\Interfaces\SignalManagerInterface;
use Hibla\EventLoop\ValueObjects\Signal;

final class SignalManager implements SignalManagerInterface
{
    /**
     * @var array<int, array<string, Signal>>
     */
    private array $signals = [];

    /**
     * @var array<int, bool>
     */
    private array $registeredSignals = [];

    public function addSignal(int $signal, callable $callback): string
    {
        if (! $this->isSignalSupported()) {
            throw new \BadMethodCallException(
                'Signal handling is not supported on this platform. ' .
                    'Please install the pcntl extension on Unix-like systems.'
            );
        }

        $id = $this->generateId();
        $signalObject = new Signal($signal, $callback, $id);

        if (! isset($this->signals[$signal])) {
            $this->signals[$signal] = [];
        }

        $this->signals[$signal][$id] = $signalObject;

        // Register the signal handler with pcntl if this is the first listener
        if (! isset($this->registeredSignals[$signal])) {
            $this->registerSignalHandler($signal);
            $this->registeredSignals[$signal] = true;
        }

        return $id;
    }

    public function removeSignal(string $signalId): bool
    {
        foreach ($this->signals as $signal => $listeners) {
            if (isset($listeners[$signalId])) {
                unset($this->signals[$signal][$signalId]);

                // If no more listeners for this signal, unregister the handler
                if ($this->signals[$signal] === []) {
                    unset($this->signals[$signal]);
                    $this->unregisterSignalHandler($signal);
                    unset($this->registeredSignals[$signal]);
                }

                return true;
            }
        }

        return false;
    }

    public function hasSignals(): bool
    {
        return $this->signals !== [];
    }

    public function processSignals(): bool
    {
        if (! $this->hasSignals()) {
            return false;
        }

        // Call pcntl_signal_dispatch to trigger any pending signals
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        return true;
    }

    public function clearAllSignals(): void
    {
        foreach (array_keys($this->registeredSignals) as $signal) {
            $this->unregisterSignalHandler($signal);
        }

        $this->signals = [];
        $this->registeredSignals = [];
    }

    public function getListenerCount(int $signal): int
    {
        return isset($this->signals[$signal]) ? \count($this->signals[$signal]) : 0;
    }

    private function registerSignalHandler(int $signal): void
    {
        pcntl_signal($signal, function (int $sig) {
            $this->handleSignal($sig);
        });
    }

    private function unregisterSignalHandler(int $signal): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal($signal, SIG_DFL);
        }
    }

    private function handleSignal(int $signal): void
    {
        if (! isset($this->signals[$signal])) {
            return;
        }

        foreach ($this->signals[$signal] as $signalObject) {
            try {
                $signalObject->invoke($signal);
            } catch (\Throwable $e) {
                // Log error but don't let one listener break others
                trigger_error(
                    \sprintf(
                        'Uncaught exception in signal handler: %s',
                        $e->getMessage()
                    ),
                    E_USER_WARNING
                );
            }
        }
    }

    private function isSignalSupported(): bool
    {
        return function_exists('pcntl_signal') && function_exists('pcntl_signal_dispatch');
    }

    private function generateId(): string
    {
        return uniqid('signal_', true);
    }
}
