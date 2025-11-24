<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\IOHandlers\Stream\StreamSelectHandler;
use Hibla\EventLoop\IOHandlers\Stream\StreamWatcherHandler;
use Hibla\EventLoop\ValueObjects\StreamWatcher;

/**
 * Manages stream watchers for an event loop.
 */
class StreamManager implements StreamManagerInterface
{
    /**
     * Stores registered stream watchers, keyed by their unique string ID.
     *
     * @var array<string, StreamWatcher>
     */
    private array $watchers = [];

    private readonly StreamWatcherHandler $watcherHandler;
    private readonly StreamSelectHandler $selectHandler;

    public function __construct()
    {
        $this->watcherHandler = new StreamWatcherHandler();
        $this->selectHandler = new StreamSelectHandler();
    }

    /**
     * Adds a new stream watcher.
     *
     * @param  resource  $stream  The stream resource to watch.
     * @param  callable  $callback  The callback to execute when the stream is ready.
     * @param  string  $type  The type of watcher (read/write).
     * @return string The unique ID of the created watcher.
     */
    public function addStreamWatcher($stream, callable $callback, string $type = StreamWatcher::TYPE_READ): string
    {
        $watcher = $this->watcherHandler->createWatcher($stream, $callback, $type);
        $this->watchers[$watcher->getId()] = $watcher;

        return $watcher->getId();
    }

    /**
     * Removes a stream watcher by its ID.
     *
     * @param  string  $watcherId  The ID of the watcher to remove.
     * @return bool True if the watcher was removed, false if it didn't exist.
     */
    public function removeStreamWatcher(string $watcherId): bool
    {
        if (isset($this->watchers[$watcherId])) {
            unset($this->watchers[$watcherId]);

            return true;
        }

        return false;
    }

    /**
     * Processes streams that are ready for I/O.
     */
    public function processStreams(): void
    {
        if (count($this->watchers) === 0) {
            return;
        }

        $readyStreams = $this->selectHandler->selectStreams($this->watchers);

        if (count($readyStreams) > 0) {
            $this->selectHandler->processReadyStreams($readyStreams, $this->watchers);
        }
    }

    /**
     * Checks if there are any registered watchers.
     *
     * @return bool True if there are watchers, false otherwise.
     */
    public function hasWatchers(): bool
    {
        return count($this->watchers) > 0;
    }

    /**
     * Clear all stream watchers.
     * Used during forced shutdown to prevent hanging.
     */
    public function clearAllWatchers(): void
    {
        $this->watchers = [];
    }
}
