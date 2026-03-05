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
     * @var array<int, resource> Map of streamId to uv_poll resource
     */
    private array $uvHandles = [];

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
     * Shared callback for all stream events
     */
    private \Closure $pollCallback;

    public function __construct($uvLoop)
    {
        $this->uvLoop = $uvLoop;

        // Shared callback handles routing LibUV events back to the PHP objects
        $this->pollCallback = function ($handle, $status, $events, $stream) {
            $streamId = (int) $stream;

            // LibUV stops polling automatically on error.
            // force read/write flags so the user's callback triggers and can handle EOF/errors via fread/fwrite.
            if ($status !== 0) {
                $events = \UV::READABLE | \UV::WRITABLE;
            }

            // Route READ events
            if (($events & \UV::READABLE) && !empty($this->readWatchers[$streamId])) {
                foreach ($this->readWatchers[$streamId] as $watcher) {
                    $watcher->execute();
                }
            }

            // Route WRITE events
            if (($events & \UV::WRITABLE) && !empty($this->writeWatchers[$streamId])) {
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

        $meta = $this->watcherIndex[$watcherId];
        $streamId = $meta['streamId'];
        $type = $meta['type'];

        if ($type === StreamWatcher::TYPE_READ) {
            unset($this->readWatchers[$streamId][$watcherId]);
            if (empty($this->readWatchers[$streamId])) {
                unset($this->readWatchers[$streamId]);
            }
        } else {
            unset($this->writeWatchers[$streamId][$watcherId]);
            if (empty($this->writeWatchers[$streamId])) {
                unset($this->writeWatchers[$streamId]);
            }
        }

        unset($this->watcherIndex[$watcherId]);

        $this->updatePollState($streamId);

        return true;
    }

    /**
     * {@inheritDoc}
     *  No-op: LibUV handles execution via the pollCallback inside uv_run()
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
        return !empty($this->readWatchers) || !empty($this->writeWatchers);
    }

    /**
     * {@inheritDoc}
     */
    public function clearAllWatchers(): void
    {
        foreach ($this->uvHandles as $handle) {
            @\uv_poll_stop($handle);
            \uv_close($handle);
        }

        $this->uvHandles = [];
        $this->readWatchers = [];
        $this->writeWatchers = [];
        $this->watcherIndex = [];
    }

    private function addStreamWatcher($stream, callable $callback, string $type): string
    {
        $watcher = new StreamWatcher($stream, $callback, $type);
        $streamId = (int) $stream;
        $watcherId = $watcher->getId();

        if ($type === StreamWatcher::TYPE_READ) {
            $this->readWatchers[$streamId][$watcherId] = $watcher;
        } else {
            $this->writeWatchers[$streamId][$watcherId] = $watcher;
        }

        $this->watcherIndex[$watcherId] = [
            'type' => $type,
            'streamId' => $streamId,
            'stream' => $stream, // Store reference for uv_poll_init_socket
        ];

        $this->updatePollState($streamId);

        return $watcherId;
    }

    private function updatePollState(int $streamId): void
    {
        $flags = 0;

        if (!empty($this->readWatchers[$streamId])) {
            $flags |= \UV::READABLE;
        }

        if (!empty($this->writeWatchers[$streamId])) {
            $flags |= \UV::WRITABLE;
        }

        if ($flags === 0) {
            // No watchers left for this stream, kill the UV handle
            if (isset($this->uvHandles[$streamId])) {
                $handle = $this->uvHandles[$streamId];
                @\uv_poll_stop($handle);
                \uv_close($handle);
                unset($this->uvHandles[$streamId]);
            }
            return;
        }

        // We have watchers, ensure the handle exists
        if (!isset($this->uvHandles[$streamId])) {
            // Recover stream resource from our index
            $stream = null;
            foreach ($this->watcherIndex as $meta) {
                if ($meta['streamId'] === $streamId) {
                    $stream = $meta['stream'];
                    break;
                }
            }

            if ($stream === null || !is_resource($stream)) {
                // Failsafe: if resource is dead, cleanup
                return;
            }

            $this->uvHandles[$streamId] = \uv_poll_init_socket($this->uvLoop, $stream);
        }

        // Apply the updated flags
        \uv_poll_start($this->uvHandles[$streamId], $flags, $this->pollCallback);
    }
}
