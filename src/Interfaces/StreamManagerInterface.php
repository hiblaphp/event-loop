<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Interfaces;

use InvalidArgumentException;

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
     * 
     * @param  string  $watcherId  The read watcher ID
     * @return bool True if removed successfully
     * @throws InvalidArgumentException If the watcher ID does not exist or is not a read watcher
     */
    public function removeReadWatcher(string $watcherId): bool;

    /**
     * Removes a write watcher by its watcher ID.
     * 
     * @param  string  $watcherId  The write watcher ID
     * @return bool True if removed successfully
     * @throws InvalidArgumentException If the watcher ID does not exist or is not a write watcher
     */
    public function removeWriteWatcher(string $watcherId): bool;

    /**
     * Adds a new stream watcher.
     *
     * @param  resource  $stream  The stream resource
     * @param  callable  $callback  The callback function
     * @param  string  $type  The watcher type (read/write)
     * @return string The watcher ID
     */
    public function addStreamWatcher(mixed $stream, callable $callback, string $type): string;

    /**
     * Removes a stream watcher by ID.
     *
     * @param  string  $watcherId  The watcher ID
     * @return bool True if removed, false if not found
     */
    public function removeStreamWatcher(string $watcherId): bool;

    /**
     * Processes streams that are ready for I/O.
     *
     * @return bool True if any streams were processed
     */
    public function processStreams(): bool;

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