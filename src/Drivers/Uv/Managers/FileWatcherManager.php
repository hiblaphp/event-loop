<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Drivers\Uv\Managers;

use Hibla\EventLoop\Interfaces\FileWatcherManagerInterface;

final class FileWatcherManager implements FileWatcherManagerInterface
{
    /** @var resource */
    private $uvLoop;

    public function __construct($uvLoop)
    {
        $this->uvLoop = $uvLoop;
    }

    public function addFileWatcher(string $path, callable $callback, array $options = []): string
    {
        // TODO: Implement uv_fs_event_init
        return uniqid('uv_fs_', true);
    }

    public function removeFileWatcher(string $watcherId): bool
    {
        return false;
    }

    public function processWatchers(): bool
    {
        // No-op: uv_run handles this
        return false;
    }

    public function hasWatchers(): bool
    {
        return false;
    }

    public function clearAllWatchers(): void
    {
        // TODO: Cleanup handles
    }
}