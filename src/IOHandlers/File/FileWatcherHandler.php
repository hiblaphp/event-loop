<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\File;

use Hibla\EventLoop\ValueObjects\FileWatcher;

final readonly class FileWatcherHandler
{
    /**
     * @param  string  $path
     * @param  callable  $callback
     * @param  array<string,mixed>  $options
     */
    public function createWatcher(
        string $path,
        callable $callback,
        array $options = []
    ): FileWatcher {
        return new FileWatcher($path, $callback, $options);
    }

    /**
     * @param  list<FileWatcher>  $watchers
     * @return bool True if any watcher detected changes.
     */
    public function processWatchers(array &$watchers): bool
    {
        $processed = false;

        /** @var FileWatcher $watcher */
        foreach ($watchers as $watcher) {
            if ($this->checkWatcher($watcher)) {
                $processed = true;
            }
        }

        return $processed;
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

    /**
     * @param  list<FileWatcher>  &$watchers  List of watchers (by reference).
     * @param  string  $watcherId  The ID to remove.
     * @return bool True if removal succeeded.
     */
    public function removeWatcher(array &$watchers, string $watcherId): bool
    {
        $initialCount = \count($watchers);

        $watchers = array_values(
            array_filter(
                $watchers,
                static fn (FileWatcher $watcher): bool => $watcher->getId() !== $watcherId
            )
        );

        return \count($watchers) < $initialCount;
    }
}
