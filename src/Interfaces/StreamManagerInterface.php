<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Interfaces;

/**
 * Interface for managing stream watchers.
 */
interface StreamManagerInterface
{
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
     */
    public function processStreams(): void;

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
