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

    /**
     * @var array<int, string> Map of stream resource ID => watcher ID for O(1) lookups
     */
    private array $streamToWatcherMap = [];

    private readonly StreamWatcherHandler $watcherHandler;
    private readonly StreamSelectHandler $selectHandler;

    public function __construct()
    {
        $this->watcherHandler = new StreamWatcherHandler();
        $this->selectHandler = new StreamSelectHandler();
    }

    /**
     * @inheritDoc
     */
    public function addStreamWatcher($stream, callable $callback, string $type = StreamWatcher::TYPE_READ): string
    {
        $watcher = $this->watcherHandler->createWatcher($stream, $callback, $type);
        $watcherId = $watcher->getId();
        
        $this->watchers[$watcherId] = $watcher;
        
        // Maintain the lookup map for O(1) access during processing
        if (\is_resource($stream)) {
            $this->streamToWatcherMap[(int) $stream] = $watcherId;
        }

        return $watcherId;
    }

    /**
     * @inheritDoc
     */
    public function removeStreamWatcher(string $watcherId): bool
    {
        if (isset($this->watchers[$watcherId])) {
            $watcher = $this->watchers[$watcherId];
            $stream = $watcher->getStream();
            
            // Remove from lookup map
            if (\is_resource($stream)) {
                unset($this->streamToWatcherMap[(int) $stream]);
            }
            
            unset($this->watchers[$watcherId]);

            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function processStreams(): bool
    {
        if (\count($this->watchers) === 0) {
            return false;
        }

        $readyStreams = $this->selectHandler->selectStreams($this->watchers);

        if (\count($readyStreams) > 0) {
            // Pass the pre-built lookup map to avoid O(n) reconstruction
            $this->selectHandler->processReadyStreams(
                $readyStreams, 
                $this->watchers,
                $this->streamToWatcherMap
            );

            return true;
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function hasWatchers(): bool
    {
        return \count($this->watchers) > 0;
    }

    /**
     * @inheritDoc
     */
    public function clearAllWatchers(): void
    {
        $this->watchers = [];
        $this->streamToWatcherMap = [];
    }
}