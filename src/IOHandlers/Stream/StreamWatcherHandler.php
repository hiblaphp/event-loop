<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Stream;

use Hibla\EventLoop\ValueObjects\StreamWatcher;

/**
 * Handles stream watcher creation and management.
 *
 * This class provides utilities for creating stream watchers,
 * executing their callbacks, and finding watchers by stream resource.
 */
final readonly class StreamWatcherHandler
{
    /**
     * Create a new stream watcher.
     *
     * Creates a StreamWatcher object that monitors the given stream
     * and executes the callback when the stream is ready.
     *
     * @param  resource  $stream  The stream resource to watch
     * @param  callable  $callback  Callback to execute when stream is ready
     * @return StreamWatcher The created stream watcher
     */
    public function createWatcher($stream, callable $callback, string $type = StreamWatcher::TYPE_READ): StreamWatcher
    {
        return new StreamWatcher($stream, $callback, $type);
    }

    /**
     * Execute a stream watcher's callback.
     *
     * Safely executes the callback associated with the stream watcher.
     *
     * @param  StreamWatcher  $watcher  The watcher to execute
     */
    public function executeWatcher(StreamWatcher $watcher): void
    {
        $watcher->execute();
    }

    /**
     * Find a stream watcher by its stream resource.
     *
     * Searches through an array of watchers to find the one
     * that matches the given stream resource.
     *
     * @param  StreamWatcher[]  $watchers  Array of watchers to search
     * @param  resource  $stream  Stream resource to find
     * @return StreamWatcher|null The matching watcher or null if not found
     */
    public function findWatcherByStream(array $watchers, $stream): ?StreamWatcher
    {
        foreach ($watchers as $watcher) {
            if ($watcher->getStream() === $stream) {
                return $watcher;
            }
        }

        return null;
    }
}
