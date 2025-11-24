<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use Hibla\EventLoop\Interfaces\SignalManagerInterface;
use Hibla\EventLoop\ValueObjects\Signal;

/**
 * Manages signal handling for the event loop.
 *
 * Allows registering callbacks to be invoked when specific Unix signals
 * are received by the process (not supported on Windows).
 */
final class SignalManager implements SignalManagerInterface
{
    /**
     * @var array<int, array<string, Signal>> signal => [id => Signal]
     */
    private array $signals = [];

    /**
     * @var array<int, bool> Tracks which signals have registered handlers
     */
    private array $registeredSignals = [];

    /**
     * Add a signal listener
     *
     * @param int $signal The signal number (e.g., SIGINT, SIGTERM)
     * @param callable $callback Function to execute when signal is received
     * @return string Unique identifier for this signal listener
     * @throws \BadMethodCallException If signals are not supported on this platform
     */
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

    /**
     * Remove a signal listener
     *
     * @param string $signalId The signal listener ID returned by addSignal()
     * @return bool True if the listener was removed, false if not found
     */
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

    /**
     * Check if there are any pending signals to process
     *
     * @return bool True if there are signals registered
     */
    public function hasSignals(): bool
    {
        return $this->signals !== [];
    }

    /**
     * Process any pending signals
     * This should be called during each event loop tick
     *
     * @return bool True if any signal was processed
     */
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

    /**
     * Clear all signal listeners
     */
    public function clearAllSignals(): void
    {
        foreach (array_keys($this->registeredSignals) as $signal) {
            $this->unregisterSignalHandler($signal);
        }

        $this->signals = [];
        $this->registeredSignals = [];
    }

    /**
     * Get count of listeners for a specific signal
     *
     * @param int $signal The signal number
     * @return int Number of listeners
     */
    public function getListenerCount(int $signal): int
    {
        return isset($this->signals[$signal]) ? count($this->signals[$signal]) : 0;
    }

    /**
     * Register a signal handler with pcntl
     *
     * @param int $signal The signal number
     */
    private function registerSignalHandler(int $signal): void
    {
        pcntl_signal($signal, function (int $sig) {
            $this->handleSignal($sig);
        });
    }

    /**
     * Unregister a signal handler
     *
     * @param int $signal The signal number
     */
    private function unregisterSignalHandler(int $signal): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal($signal, SIG_DFL);
        }
    }

    /**
     * Handle a received signal by calling all registered listeners
     *
     * @param int $signal The signal number that was received
     */
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
                    sprintf(
                        'Uncaught exception in signal handler: %s',
                        $e->getMessage()
                    ),
                    E_USER_WARNING
                );
            }
        }
    }

    /**
     * Check if signal handling is supported on this platform
     *
     * @return bool True if signals are supported
     */
    private function isSignalSupported(): bool
    {
        return function_exists('pcntl_signal') && function_exists('pcntl_signal_dispatch');
    }

    /**
     * Generate a unique ID for a signal listener
     *
     * @return string Unique identifier
     */
    private function generateId(): string
    {
        return uniqid('signal_', true);
    }
}
