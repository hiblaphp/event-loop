<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use Hibla\EventLoop\Interfaces\FileWatcherManagerInterface;
use Hibla\EventLoop\ValueObjects\FileWatcher;

final class FileWatcherManager implements FileWatcherManagerInterface
{
    /**
     * @var list<FileWatcher>
     */
    private array $watchers = [];

    /**
     * @var array<string, FileWatcher>
     */
    private array $watchersById = [];

    /**
     * @inheritDoc
     */
    public function addFileWatcher(string $path, callable $callback, array $options = []): string
    {
        $watcher = new FileWatcher($path, $callback, $options);

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

        $initialCount = \count($this->watchers);

        $this->watchers = array_values(
            array_filter(
                $this->watchers,
                static fn (FileWatcher $watcher): bool => $watcher->getId() !== $watcherId
            )
        );

        return \count($this->watchers) < $initialCount;
    }

    /**
     * @inheritDoc
     */
    public function processWatchers(): bool
    {
        $processed = false;

        foreach ($this->watchers as $watcher) {
            if ($this->checkWatcher($watcher)) {
                $processed = true;
            }
        }

        return $processed;
    }

    /**
     * @inheritDoc
     */
    public function hasWatchers(): bool
    {
        return \count($this->watchers) > 0;
    }

    /**
     * @inheritDoc
     */
    public function clearAllWatchers(): void
    {
        $this->watchers = [];
        $this->watchersById = [];
    }

    private function checkWatcher(FileWatcher $watcher): bool
    {
        if (! $watcher->shouldCheck()) {
            return false;
        }

        if (! $watcher->checkForChanges()) {
            return false;
        }

        $eventType = file_exists($watcher->getPath()) ? 'modified' : 'deleted';
        $watcher->executeCallback($eventType, $watcher->getPath());

        return true;
    }
}
