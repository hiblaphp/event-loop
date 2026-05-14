<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Drivers\StreamSelect\Managers;

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

        $meta     = $this->watcherIndex[$watcherId];
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
        $watcher  = new StreamWatcher($stream, $callback, $type);
        $streamId = (int) $stream;
        $watcherId = $watcher->getId();

        if ($type === StreamWatcher::TYPE_READ) {
            $this->readWatchers[$streamId][$watcherId] = $watcher;
        } else {
            $this->writeWatchers[$streamId][$watcherId] = $watcher;
        }

        $this->watcherIndex[$watcherId] = [
            'type'     => $type,
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
        $this->readWatchers  = [];
        $this->writeWatchers = [];
        $this->watcherIndex  = [];
    }

    /**
     * Calls stream_select() on the given read and write watcher sets, returning
     * the streams that are ready for I/O.
     *
     * On Windows, failed outbound connection attempts are not reported through
     * the write set like they are on POSIX platforms. Instead they surface via
     * the exceptional-conditions set ($except). To keep the public API uniform
     * across platforms, write-only sockets that appear to be in a pending
     * connection state (position 0, not already in the read set) are added to
     * $except automatically and merged back into the write result afterwards,
     * so existing write callbacks receive and handle the error naturally.
     *
     * EINTR warnings emitted when a signal interrupts the syscall are suppressed
     * via a scoped error handler; all other PHP warnings are forwarded to
     * whichever error handler the application has registered.
     *
     * @param  array<int, array<string, StreamWatcher>>  $readWatchers
     * @param  array<int, array<string, StreamWatcher>>  $writeWatchers
     * @return array{read: array<resource>, write: array<resource>}
     */
    private function selectStreams(
        array $readWatchers,
        array $writeWatchers,
        int $timeoutMicroseconds = self::DEFAULT_TIMEOUT_MICROSECONDS,
    ): array {
        $read  = [];
        $write = [];

        foreach ($readWatchers as $watchers) {
            $watcher = reset($watchers);
            if ($watcher !== false) {
                $stream = $watcher->getStream();
                if (\is_resource($stream)) {
                    $read[(int) $stream] = $stream;
                }
            }
        }

        foreach ($writeWatchers as $watchers) {
            $watcher = reset($watchers);
            if ($watcher !== false) {
                $stream = $watcher->getStream();
                if (\is_resource($stream)) {
                    $write[(int) $stream] = $stream;
                }
            }
        }

        if (\count($read) === 0 && \count($write) === 0) {
            return ['read' => [], 'write' => []];
        }

        // Windows does not report failed outbound connection attempts through
        // writefds like POSIX platforms do. Instead it uses exceptfds instead.
        // It approximate pending-connect sockets as write-only sockets at
        // stream position 0 and add them to $except so they are not silently
        // dropped. After stream_select() they are merged back into $write so
        // the rest of the dispatch loop requires no platform-specific branches.
        // @link https://docs.microsoft.com/en-us/windows/win32/api/winsock2/nf-winsock2-select
        $except = null;
        if (\PHP_OS_FAMILY === 'Windows') {
            $except = [];
            foreach ($write as $key => $socket) {
                if (! isset($read[$key]) && @\ftell($socket) === 0) {
                    $except[$key] = $socket;
                }
            }
        }

        $seconds      = intdiv($timeoutMicroseconds, 1_000_000);
        $microseconds = $timeoutMicroseconds % 1_000_000;

        // Use a scoped error handler rather than @ suppression so that only
        // EINTR interruption warnings are silenced. Any other warning is
        // forwarded to the application's registered error handler as normal.
        $previous = \set_error_handler(
            function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use (&$previous): bool {
                $eintr = \defined('SOCKET_EINTR') ? \SOCKET_EINTR : (\defined('PCNTL_EINTR') ? \PCNTL_EINTR : 4);
                if ($errno === \E_WARNING && \str_contains($errstr, '[' . $eintr . ']: ')) {
                    return true;
                }

                if ($previous !== null) {
                    return (bool) ($previous)($errno, $errstr, $errfile, $errline);
                }

                return false;
            }
        );

        try {
            $result = \stream_select($read, $write, $except, $seconds, $microseconds);
            \restore_error_handler();
        } catch (\Throwable $e) {
            \restore_error_handler();
            throw $e;
        }

        if ($result === false) {
            return ['read' => [], 'write' => []];
        }

        if ($except !== null && \count($except) > 0) {
            $write = [...$write, ...$except];
        }

        return [
            'read'  => $read,
            'write' => $write,
        ];
    }
}
