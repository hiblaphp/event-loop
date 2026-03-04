<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\ValueObjects\StreamWatcher;
use InvalidArgumentException;

final class StreamManager implements StreamManagerInterface
{
    /**
     * Default timeout used when no explicit timeout is passed by the caller.
     * WorkHandler always passes a calculated value so this is only a safety
     * fallback for direct/test callers that omit the argument.
     */
    private const int DEFAULT_TIMEOUT_MICROSECONDS = 200_000; // 200ms — PHP manual recommended minimum

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

        $meta = $this->watcherIndex[$watcherId];

        if ($meta['type'] !== StreamWatcher::TYPE_READ) {
            throw new InvalidArgumentException(
                "Watcher '{$watcherId}' is not a READ watcher"
            );
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

        $meta = $this->watcherIndex[$watcherId];

        if ($meta['type'] !== StreamWatcher::TYPE_WRITE) {
            throw new InvalidArgumentException(
                "Watcher '{$watcherId}' is not a WRITE watcher"
            );
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

        if ($meta['type'] === StreamWatcher::TYPE_READ) {
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

        return true;
    }

    /**
     * @param resource $stream
     */
    private function addStreamWatcher($stream, callable $callback, string $type = StreamWatcher::TYPE_READ): string
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
        ];

        return $watcherId;
    }

    /**
     * {@inheritDoc}
     */
    public function processStreams(int $timeoutMicroseconds = self::DEFAULT_TIMEOUT_MICROSECONDS): bool
    {
        if (\count($this->readWatchers) === 0 && \count($this->writeWatchers) === 0) {
            return false;
        }

        $readyStreams = $this->selectStreams(
            $this->readWatchers,
            $this->writeWatchers,
            $timeoutMicroseconds
        );

        $hasActivity = false;

        foreach ($readyStreams['read'] as $stream) {
            $streamId = (int) $stream;
            if (isset($this->readWatchers[$streamId])) {
                $watchers = $this->readWatchers[$streamId];
                foreach ($watchers as $watcherId => $watcher) {
                    if (isset($this->readWatchers[$streamId][$watcherId])) {
                        $watcher->execute();
                        $hasActivity = true;
                    }
                }
            }
        }

        foreach ($readyStreams['write'] as $stream) {
            $streamId = (int) $stream;
            if (isset($this->writeWatchers[$streamId])) {
                $watchers = $this->writeWatchers[$streamId];
                foreach ($watchers as $watcherId => $watcher) {
                    if (isset($this->writeWatchers[$streamId][$watcherId])) {
                        $watcher->execute();
                        $hasActivity = true;
                    }
                }
            }
        }

        return $hasActivity;
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
        $this->readWatchers = [];
        $this->writeWatchers = [];
        $this->watcherIndex = [];
    }

    /**
     * @param  array<int, array<string, StreamWatcher>>  $readWatchers
     * @param  array<int, array<string, StreamWatcher>>  $writeWatchers
     * @return array{read: array<resource>, write: array<resource>}
     */
    private function selectStreams(
        array $readWatchers,
        array $writeWatchers,
        int $timeoutMicroseconds = self::DEFAULT_TIMEOUT_MICROSECONDS,
    ): array {
        $read = [];
        $write = [];
        $except = null;

        foreach ($readWatchers as $watchers) {
            $watcher = reset($watchers);
            if ($watcher !== false) {
                $stream = $watcher->getStream();
                if (\is_resource($stream)) {
                    $read[] = $stream;
                }
            }
        }

        foreach ($writeWatchers as $watchers) {
            $watcher = reset($watchers);
            if ($watcher !== false) {
                $stream = $watcher->getStream();
                if (\is_resource($stream)) {
                    $write[] = $stream;
                }
            }
        }

        if (\count($read) === 0 && \count($write) === 0) {
            return ['read' => [], 'write' => []];
        }

        @stream_select($read, $write, $except, 0, $timeoutMicroseconds);

        return [
            'read' => \count($read) > 0 ? $read : [],
            'write' => \count($write) > 0 ? $write : [],
        ];
    }
}
