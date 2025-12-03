<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\IOHandlers\Stream\StreamSelectHandler;
use Hibla\EventLoop\IOHandlers\Stream\StreamWatcherHandler;
use Hibla\EventLoop\ValueObjects\StreamWatcher;

final class StreamManager implements StreamManagerInterface
{
    /**
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
     * @param  resource  $stream
     * @param  callable  $callback
     * @param  string  $type
     * @return string
     */
    public function addStreamWatcher($stream, callable $callback, string $type = StreamWatcher::TYPE_READ): string
    {
        $watcher = $this->watcherHandler->createWatcher($stream, $callback, $type);
        $this->watchers[$watcher->getId()] = $watcher;

        return $watcher->getId();
    }

    public function removeStreamWatcher(string $watcherId): bool
    {
        if (isset($this->watchers[$watcherId])) {
            unset($this->watchers[$watcherId]);

            return true;
        }

        return false;
    }

    public function processStreams(): bool
    {
        if (\count($this->watchers) === 0) {
            return false;
        }

        $readyStreams = $this->selectHandler->selectStreams($this->watchers);

        if (\count($readyStreams) > 0) {
            $this->selectHandler->processReadyStreams($readyStreams, $this->watchers);

            return true;
        }

        return false;
    }

    public function hasWatchers(): bool
    {
        return \count($this->watchers) > 0;
    }

    public function clearAllWatchers(): void
    {
        $this->watchers = [];
    }
}
