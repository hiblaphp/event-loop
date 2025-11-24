<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use Hibla\EventLoop\Interfaces\FileManagerInterface;
use Hibla\EventLoop\IOHandlers\File\FileOperationHandler;
use Hibla\EventLoop\IOHandlers\File\FileWatcherHandler;
use Hibla\EventLoop\ValueObjects\FileOperation;
use Hibla\EventLoop\ValueObjects\FileWatcher;

/**
 * Manages all asynchronous file operations and file watchers for the event loop.
 *
 * This class serves as the central point for queuing, canceling, and processing
 * all file-related tasks, including I/O operations and filesystem watching.
 */
final class FileManager implements FileManagerInterface
{
    /** @var list<FileOperation> A queue of file operations waiting to be executed. */
    private array $pendingOperations = [];

    /** @var array<string, FileOperation> A map of all operations by their unique ID for quick lookups. */
    private array $operationsById = [];

    /** @var list<FileWatcher> A list of all active file watchers for iteration. */
    private array $watchers = [];

    /** @var array<string, FileWatcher> A map of all watchers by their unique ID for quick lookups. */
    private array $watchersById = [];

    private readonly FileOperationHandler $operationHandler;
    private readonly FileWatcherHandler $watcherHandler;

    public function __construct()
    {
        // Pass a cleanup callback to the operation handler
        $this->operationHandler = new FileOperationHandler(
            fn (string $operationId) => $this->removeCompletedOperation($operationId)
        );
        $this->watcherHandler = new FileWatcherHandler();
    }

    /**
     * Adds a new file operation to the processing queue.
     *
     * @param  string  $type  The type of file operation (e.g., 'read', 'write').
     * @param  string  $path  The file path for the operation.
     * @param  mixed  $data  The data for the operation (e.g., content to write).
     * @param  callable  $callback  The callback to execute upon completion.
     * @param  array<string, mixed>  $options  Additional options for the operation.
     * @return string The unique ID of the created operation.
     */
    public function addFileOperation(
        string $type,
        string $path,
        mixed $data,
        callable $callback,
        array $options = []
    ): string {
        $operation = $this->operationHandler->createOperation($type, $path, $data, $callback, $options);

        $this->pendingOperations[] = $operation;
        $this->operationsById[$operation->getId()] = $operation;

        return $operation->getId();
    }

    /**
     * Cancels a pending file operation.
     *
     * @param  string  $operationId  The unique ID of the operation to cancel.
     * @return bool True if the operation was found and canceled, false otherwise.
     */
    public function cancelFileOperation(string $operationId): bool
    {
        if (! isset($this->operationsById[$operationId])) {
            return false;
        }

        $operationToCancel = $this->operationsById[$operationId];
        $operationToCancel->cancel();

        // Remove from pending queue if it hasn't started yet
        $this->pendingOperations = array_values(
            array_filter(
                $this->pendingOperations,
                static fn (FileOperation $op): bool => $op->getId() !== $operationId
            )
        );

        return true;
    }

    /**
     * Removes a completed operation from tracking.
     * Called by FileOperationHandler when an operation completes, fails, or is cancelled.
     *
     * @param  string  $operationId  The unique ID of the operation to remove.
     */
    private function removeCompletedOperation(string $operationId): void
    {
        unset($this->operationsById[$operationId]);
    }

    /**
     * Adds a new file watcher to be monitored.
     *
     * @param  string  $path  The file or directory path to watch.
     * @param  callable  $callback  The callback to execute when a change is detected.
     * @param  array<string, mixed>  $options  Additional options for the watcher (e.g., polling interval).
     * @return string The unique ID of the created watcher.
     */
    public function addFileWatcher(string $path, callable $callback, array $options = []): string
    {
        $watcher = $this->watcherHandler->createWatcher($path, $callback, $options);

        // Maintain two representations: a list for iteration and a map for lookups.
        $this->watchers[] = $watcher;
        $this->watchersById[$watcher->getId()] = $watcher;

        return $watcher->getId();
    }

    /**
     * Removes an active file watcher.
     *
     * @param  string  $watcherId  The unique ID of the watcher to remove.
     * @return bool True if the watcher was found and removed, false otherwise.
     */
    public function removeFileWatcher(string $watcherId): bool
    {
        if (! isset($this->watchersById[$watcherId])) {
            return false;
        }

        unset($this->watchersById[$watcherId]);

        // The handler modifies the list of watchers by reference.
        return $this->watcherHandler->removeWatcher($this->watchers, $watcherId);
    }

    /**
     * Processes all pending file operations and active watchers for one event loop tick.
     *
     * @return bool True if any work was done (operation processed or watcher checked), false otherwise.
     */
    public function processFileOperations(): bool
    {
        $workDone = false;

        // Process pending operations
        if ($this->processPendingOperations()) {
            $workDone = true;
        }

        // Process file watchers
        if ($this->processFileWatchers()) {
            $workDone = true;
        }

        return $workDone;
    }

    /**
     * Executes all file operations currently in the queue.
     *
     * @return bool True if at least one operation was processed.
     */
    private function processPendingOperations(): bool
    {
        if (count($this->pendingOperations) === 0) {
            return false;
        }

        $processed = false;
        $operationsToProcess = $this->pendingOperations;
        $this->pendingOperations = [];

        foreach ($operationsToProcess as $operation) {
            // Skip cancelled operations entirely
            if ($operation->isCancelled()) {
                // Clean up immediately for cancelled operations
                unset($this->operationsById[$operation->getId()]);

                continue;
            }

            if ($this->operationHandler->executeOperation($operation)) {
                $processed = true;
            }
        }

        return $processed;
    }

    /**
     * Checks all active file watchers for changes.
     *
     * @return bool True if any watcher detected a change.
     */
    private function processFileWatchers(): bool
    {
        // The watchers array is passed by reference and may be modified by the handler.
        return $this->watcherHandler->processWatchers($this->watchers);
    }

    /**
     * Checks if there is any pending file-related work.
     *
     * @return bool True if there are pending operations or active watchers.
     */
    public function hasWork(): bool
    {
        return count($this->pendingOperations) > 0
            || count($this->operationsById) > 0
            || count($this->watchers) > 0;
    }

    /**
     * Checks if there are any pending file operations in the queue.
     *
     * @return bool True if the pending operations queue is not empty.
     */
    public function hasPendingOperations(): bool
    {
        return count($this->pendingOperations) > 0 || count($this->operationsById) > 0;
    }

    /**
     * Checks if there are any active file watchers.
     *
     * @return bool True if there are active watchers.
     */
    public function hasWatchers(): bool
    {
        return count($this->watchers) > 0;
    }

    /**
     * Clear all pending file operations and watchers.
     * Used during forced shutdown to prevent hanging.
     */
    public function clearAllOperations(): void
    {
        foreach ($this->pendingOperations as $operation) {
            $operation->cancel();
        }

        foreach ($this->operationsById as $operation) {
            $operation->cancel();
        }

        $this->pendingOperations = [];
        $this->operationsById = [];
        $this->watchers = [];
        $this->watchersById = [];
    }
}
