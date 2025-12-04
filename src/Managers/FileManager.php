<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use Hibla\EventLoop\Interfaces\FileManagerInterface;
use Hibla\EventLoop\IOHandlers\File\FileOperationHandler;
use Hibla\EventLoop\IOHandlers\File\FileWatcherHandler;
use Hibla\EventLoop\ValueObjects\FileOperation;
use Hibla\EventLoop\ValueObjects\FileWatcher;

final class FileManager implements FileManagerInterface
{
    /**
     * @var list<FileOperation>
     */
    private array $pendingOperations = [];

    /**
     * @var array<string, FileOperation>
     */
    private array $operationsById = [];

    /**
     * @var list<FileWatcher>
     */
    private array $watchers = [];

    /**
     * @var array<string, FileWatcher>
     */
    private array $watchersById = [];

    private readonly FileOperationHandler $operationHandler;
    private readonly FileWatcherHandler $watcherHandler;

    public function __construct()
    {
        $this->operationHandler = new FileOperationHandler(
            fn (string $operationId) => $this->removeCompletedOperation($operationId)
        );
        $this->watcherHandler = new FileWatcherHandler();
    }

    /**
     * @inheritDoc
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
     * @inheritDoc
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
     * @inheritDoc
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
     * @inheritDoc
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
     * @inheritDoc
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

    private function processPendingOperations(): bool
    {
        if (\count($this->pendingOperations) === 0) {
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
     * @inheritDoc
     */
    public function hasWork(): bool
    {
        return \count($this->pendingOperations) > 0
            || \count($this->operationsById) > 0
            || \count($this->watchers) > 0;
    }

    /**
     * @inheritDoc
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

    public function hasPendingOperations(): bool
    {
        return \count($this->pendingOperations) > 0 || \count($this->operationsById) > 0;
    }

    public function hasWatchers(): bool
    {
        return \count($this->watchers) > 0;
    }

    private function removeCompletedOperation(string $operationId): void
    {
        unset($this->operationsById[$operationId]);
    }

    private function processFileWatchers(): bool
    {
        return $this->watcherHandler->processWatchers($this->watchers);
    }
}
