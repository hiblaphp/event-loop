<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Drivers\Uv\Managers;

use Hibla\EventLoop\Interfaces\StreamManagerInterface;

final class StreamManager implements StreamManagerInterface
{
    /**
     *  @var resource 
     */
    private $uvLoop;

    public function __construct($uvLoop)
    {
        $this->uvLoop = $uvLoop;
    }

    public function addReadWatcher($stream, callable $callback): string
    {
        // TODO: Implement uv_poll_init_socket for read
        return uniqid('uv_read_', true);
    }

    public function addWriteWatcher($stream, callable $callback): string
    {
        // TODO: Implement uv_poll_init_socket for write
        return uniqid('uv_write_', true);
    }

    public function removeReadWatcher(string $watcherId): bool
    {
        return false;
    }

    public function removeWriteWatcher(string $watcherId): bool
    {
        return false;
    }

    public function removeStreamWatcher(string $watcherId): bool
    {
        return false;
    }

    public function processStreams(int $timeoutMicroseconds = 200_000): bool
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