<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Interfaces;

/**
 * Interface for managing asynchronous file watchers.
 */
interface FileWatcherManagerInterface
{
    /**
     * Adds a new file watcher.
     *
     * @param  string  $path  The path to watch
     * @param  callable  $callback  The change callback
     * @param  array<string, mixed>  $options  Watcher options
     * @return string The watcher ID
     */
    public function addFileWatcher(string $path, callable $callback, array $options = []): string;

    /**
     * Removes a file watcher.
     *
     * @param  string  $watcherId  The watcher ID to remove
     * @return bool True if removed, false if not found
     */
    public function removeFileWatcher(string $watcherId): bool;

    /**
     * Processes file watchers for one tick.
     *
     * @return bool True if any work was done
     */
    public function processWatchers(): bool;

    /**
     * Checks if there are any active watchers.
     *
     * @return bool True if there are watchers
     */
    public function hasWatchers(): bool;

    /**
     * Clears all file watchers.
     */
    public function clearAllWatchers(): void;
}
