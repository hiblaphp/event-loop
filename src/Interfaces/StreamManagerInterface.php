<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Interfaces;

/**
 * Interface for managing stream watchers.
 */
interface StreamManagerInterface
{
    /**
     * Adds a watcher for read operations on a stream for event loop to call when stream is ready for reading.
     *
     * @param  resource  $stream  The stream resource
     * @param  callable  $callback  The callback function
     * @return string The watcher ID
     */
    public function addReadWatcher($stream, callable $callback): string;

    /**
     * Adds a watcher for write operations on a stream for event loop to call when stream is ready for writing.
     *
     * @param  resource  $stream  The stream resource
     * @param  callable  $callback  The callback function
     * @return string The watcher ID
     */
    public function addWriteWatcher($stream, callable $callback): string;

    /**
     * Removes a read watcher by its watcher ID.
     * Idempotent - safe to call multiple times with the same ID.
     *
     * @param  string  $watcherId  The read watcher ID
     * @return bool True if removed, false if not found or not a read watcher
     */
    public function removeReadWatcher(string $watcherId): bool;

    /**
     * Removes a write watcher by its watcher ID.
     * Idempotent - safe to call multiple times with the same ID.
     *
     * @param  string  $watcherId  The write watcher ID
     * @return bool True if removed, false if not found or not a write watcher
     */
    public function removeWriteWatcher(string $watcherId): bool;

    /**
     * Removes a stream watcher by ID.
     * Can remove both read and write watchers.
     * Idempotent - safe to call multiple times with the same ID.
     *
     * @param  string  $watcherId  The watcher ID
     * @return bool True if removed, false if not found
     */
    public function removeStreamWatcher(string $watcherId): bool;

    /**
     * Process all active stream watchers.
     *
     * @param  int  $timeoutMicroseconds  How long stream_select may block
     *                                    waiting for I/O activity. Callers
     *                                    should pass the time until the next
     *                                    scheduled timer so the loop wakes up
     *                                    exactly when needed.
     */
    public function processStreams(int $timeoutMicroseconds = 200_000): bool;

    /**
     * Checks if there are any registered watchers.
     *
     * @return bool True if there are watchers
     */
    public function hasWatchers(): bool;

    /**
     * Clears all stream watchers.
     */
    public function clearAllWatchers(): void;
}
