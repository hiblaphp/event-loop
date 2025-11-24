<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Interfaces;

/**
 * Interface for managing asynchronous file operations and watchers.
 */
interface FileManagerInterface
{
    /**
     * Adds a new file operation to the processing queue.
     *
     * @param  string  $type  The operation type
     * @param  string  $path  The file path
     * @param  mixed  $data  The operation data
     * @param  callable  $callback  The completion callback
     * @param  array<string, mixed>  $options  Additional options
     * @return string The operation ID
     */
    public function addFileOperation(
        string $type,
        string $path,
        mixed $data,
        callable $callback,
        array $options = []
    ): string;

    /**
     * Cancels a pending file operation.
     *
     * @param  string  $operationId  The operation ID to cancel
     * @return bool True if cancelled, false if not found
     */
    public function cancelFileOperation(string $operationId): bool;

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
     * Processes file operations and watchers for one tick.
     *
     * @return bool True if any work was done
     */
    public function processFileOperations(): bool;

    /**
     * Checks if there is pending file work.
     *
     * @return bool True if there are operations or watchers
     */
    public function hasWork(): bool;

    /**
     * Clears all file operations and watchers.
     */
    public function clearAllOperations(): void;
}
