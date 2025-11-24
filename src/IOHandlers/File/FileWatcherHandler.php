<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\File;

use Hibla\EventLoop\ValueObjects\FileWatcher;

/**
 * Manages file watchers: creation, polling, and removal.
 */
final readonly class FileWatcherHandler
{
    /**
     * Create a new FileWatcher.
     *
     * @param  string  $path  File or directory to watch.
     * @param  callable  $callback  fn(string $event, string $path): void.
     * @param  array<string,mixed>  $options  ['interval'=>int (ms), ...].
     */
    public function createWatcher(
        string $path,
        callable $callback,
        array $options = []
    ): FileWatcher {
        return new FileWatcher($path, $callback, $options);
    }

    /**
     * Poll each watcher and invoke callbacks for those with changes.
     *
     * @param  list<FileWatcher>  $watchers  List of watchers to process (by reference).
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

    /**
     * Check a single watcher for changes and execute its callback if needed.
     *
     * @return bool True if callback executed.
     */
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
     * Remove a watcher by its unique ID, preserving the list structure.
     *
     * This method uses array_filter and array_values to ensure that the array
     * passed by reference remains a `list` (sequentially indexed), which
     * satisfies strict static analysis rules.
     *
     * @param  list<FileWatcher>  &$watchers  List of watchers (by reference).
     * @param  string  $watcherId  The ID to remove.
     * @return bool True if removal succeeded.
     */
    public function removeWatcher(array &$watchers, string $watcherId): bool
    {
        $initialCount = count($watchers);

        $watchers = array_values(
            array_filter(
                $watchers,
                static fn (FileWatcher $watcher): bool => $watcher->getId() !== $watcherId
            )
        );

        return count($watchers) < $initialCount;
    }
}
