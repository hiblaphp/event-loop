<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Drivers\Uv\Managers;

use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\ValueObjects\StreamWatcher;
use InvalidArgumentException;

final class StreamManager implements StreamManagerInterface
{
    /**
     * @var resource
     */
    private $uvLoop;

    /**
     * @var array<int, resource> Map of streamId => uv_poll handle
     */
    private array $uvHandles = [];

    /**
     * @var array<int, resource> Map of streamId => stream resource
     */
    private array $streamResources = [];

    /**
     * @var array<int, array<string, StreamWatcher>>
     */
    private array $readWatchers = [];

    /**
     * @var array<int, array<string, StreamWatcher>>
     */
    private array $writeWatchers = [];

    /**
     * @var array<string, array{type: string, streamId: int}>
     */
    private array $watcherIndex = [];

    /**
     * Shared callback for all stream events.
     */
    private \Closure $pollCallback;

    public function __construct($uvLoop)
    {
        $this->uvLoop = $uvLoop;

        $this->pollCallback = function ($handle, $status, $events, $stream): void {
            $streamId = (int) $stream;

            if ($status !== 0) {
                $events = \UV::READABLE | \UV::WRITABLE;
            }

            if (($events & \UV::READABLE) && \count($this->readWatchers[$streamId] ?? []) > 0) {
                foreach ($this->readWatchers[$streamId] as $watcher) {
                    $watcher->execute();
                }
            }

            if (($events & \UV::WRITABLE) && \count($this->writeWatchers[$streamId] ?? []) > 0) {
                foreach ($this->writeWatchers[$streamId] as $watcher) {
                    $watcher->execute();
                }
            }
        };
    }

    /**
     * {@inheritDoc}
     */
    public function addReadWatcher($stream, callable $callback): string
    {
        return $this->addStreamWatcher($stream, $callback, StreamWatcher::TYPE_READ);
    }

    /**
     * {@inheritDoc}
     */
    public function addWriteWatcher($stream, callable $callback): string
    {
        return $this->addStreamWatcher($stream, $callback, StreamWatcher::TYPE_WRITE);
    }

    /**
     * {@inheritDoc}
     */
    public function removeReadWatcher(string $watcherId): bool
    {
        if (! isset($this->watcherIndex[$watcherId])) {
            return false;
        }

        if ($this->watcherIndex[$watcherId]['type'] !== StreamWatcher::TYPE_READ) {
            throw new InvalidArgumentException("Watcher '{$watcherId}' is not a READ watcher");
        }

        return $this->removeStreamWatcher($watcherId);
    }

    /**
     * {@inheritDoc}
     */
    public function removeWriteWatcher(string $watcherId): bool
    {
        if (! isset($this->watcherIndex[$watcherId])) {
            return false;
        }

        if ($this->watcherIndex[$watcherId]['type'] !== StreamWatcher::TYPE_WRITE) {
            throw new InvalidArgumentException("Watcher '{$watcherId}' is not a WRITE watcher");
        }

        return $this->removeStreamWatcher($watcherId);
    }

    /**
     * {@inheritDoc}
     */
    public function removeStreamWatcher(string $watcherId): bool
    {
        if (! isset($this->watcherIndex[$watcherId])) {
            return false;
        }

        $meta     = $this->watcherIndex[$watcherId];
        $streamId = $meta['streamId'];
        $type     = $meta['type'];

        if ($type === StreamWatcher::TYPE_READ) {
            unset($this->readWatchers[$streamId][$watcherId]);
            if (\count($this->readWatchers[$streamId]) === 0) {
                unset($this->readWatchers[$streamId]);
            }
        } else {
            unset($this->writeWatchers[$streamId][$watcherId]);
            if (\count($this->writeWatchers[$streamId]) === 0) {
                unset($this->writeWatchers[$streamId]);
            }
        }

        unset($this->watcherIndex[$watcherId]);

        if (\count($this->readWatchers[$streamId] ?? []) === 0
            && \count($this->writeWatchers[$streamId] ?? []) === 0
        ) {
            unset($this->streamResources[$streamId]);
        }

        $this->updatePollState($streamId);

        return true;
    }

    /**
     * {@inheritDoc}
     * No-op: LibUV drives execution via pollCallback inside uv_run().
     */
    public function processStreams(int $timeoutMicroseconds = 200_000): bool
    {
        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function hasWatchers(): bool
    {
        return \count($this->readWatchers) > 0 || \count($this->writeWatchers) > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function clearAllWatchers(): void
    {
        foreach ($this->uvHandles as $handle) {
            if (\uv_is_active($handle)) {
                \uv_poll_stop($handle);
            }
            \uv_close($handle);
        }

        $this->uvHandles       = [];
        $this->streamResources = [];
        $this->readWatchers    = [];
        $this->writeWatchers   = [];
        $this->watcherIndex    = [];
    }

    /**
     * @param resource $stream
     */
    private function addStreamWatcher($stream, callable $callback, string $type): string
    {
        $watcher   = new StreamWatcher($stream, $callback, $type);
        $streamId  = (int) $stream;
        $watcherId = $watcher->getId();

        $this->streamResources[$streamId] = $stream;

        if ($type === StreamWatcher::TYPE_READ) {
            $this->readWatchers[$streamId][$watcherId] = $watcher;
        } else {
            $this->writeWatchers[$streamId][$watcherId] = $watcher;
        }

        $this->watcherIndex[$watcherId] = [
            'type'     => $type,
            'streamId' => $streamId,
        ];

        $this->updatePollState($streamId);

        return $watcherId;
    }

    private function updatePollState(int $streamId): void
    {
        $flags = 0;

        if (\count($this->readWatchers[$streamId] ?? []) > 0) {
            $flags |= \UV::READABLE;
        }

        if (\count($this->writeWatchers[$streamId] ?? []) > 0) {
            $flags |= \UV::WRITABLE;
        }

        if ($flags === 0) {
            if (isset($this->uvHandles[$streamId])) {
                $handle = $this->uvHandles[$streamId];
                if (\uv_is_active($handle)) {
                    \uv_poll_stop($handle);
                }
                \uv_close($handle);
                unset($this->uvHandles[$streamId]);
            }

            return;
        }

        if (! isset($this->uvHandles[$streamId])) {
            $stream = $this->streamResources[$streamId] ?? null;

            if ($stream === null || ! \is_resource($stream)) {
                $this->cleanupDeadStream($streamId);

                return;
            }

            $handle = \uv_poll_init_socket($this->uvLoop, $stream);

            if ($handle === false) {
                $handle = \uv_poll_init($this->uvLoop, $stream);
            }

            if ($handle === false) {
                $this->cleanupDeadStream($streamId);

                return;
            }

            $this->uvHandles[$streamId] = $handle;
        }

        \uv_poll_start($this->uvHandles[$streamId], $flags, $this->pollCallback);
    }

    private function cleanupDeadStream(int $streamId): void
    {
        unset($this->readWatchers[$streamId]);
        unset($this->writeWatchers[$streamId]);
        unset($this->streamResources[$streamId]);

        foreach ($this->watcherIndex as $watcherId => $meta) {
            if ($meta['streamId'] === $streamId) {
                unset($this->watcherIndex[$watcherId]);
            }
        }
    }
}