<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Interfaces;

/**
 * Interface for managing signal handling.
 */
interface SignalManagerInterface
{
    /**
     * Adds a signal listener.
     *
     * @param  int  $signal  The signal number
     * @param  callable  $callback  The callback function
     * @return string The signal listener ID
     * @throws \BadMethodCallException If signals are not supported
     */
    public function addSignal(int $signal, callable $callback): string;

    /**
     * Removes a signal listener by ID.
     *
     * @param  string  $signalId  The signal listener ID
     * @return bool True if removed, false if not found
     */
    public function removeSignal(string $signalId): bool;

    /**
     * Checks if there are any registered signals.
     *
     * @return bool True if there are signals
     */
    public function hasSignals(): bool;

    /**
     * Processes any pending signals.
     *
     * @return bool True if signal processing was attempted
     */
    public function processSignals(): bool;

    /**
     * Clears all signal listeners.
     */
    public function clearAllSignals(): void;

    /**
     * Gets the number of listeners for a signal.
     *
     * @param  int  $signal  The signal number
     * @return int Number of listeners
     */
    public function getListenerCount(int $signal): int;
}
