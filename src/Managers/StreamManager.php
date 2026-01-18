<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\IOHandlers\Stream\StreamSelectHandler;
use Hibla\EventLoop\ValueObjects\StreamWatcher;
use InvalidArgumentException;

final class StreamManager implements StreamManagerInterface
{
    /**
     * @var array<string, StreamWatcher> All watchers indexed by their unique ID
     */
    private array $watchers = [];

    /**
     * @var array<int, string> Maps stream resource ID to READ watcher ID
     */
    private array $readWatchers = [];

    /**
     * @var array<int, string> Maps stream resource ID to WRITE watcher ID
     */
    private array $writeWatchers = [];

    private readonly StreamSelectHandler $selectHandler;

    public function __construct()
    {
        $this->selectHandler = new StreamSelectHandler();
    }

    /**
     * @inheritDoc
     */
    public function addReadWatcher($stream, callable $callback): string
    {
        return $this->addStreamWatcher($stream, $callback, StreamWatcher::TYPE_READ);
    }

    /**
     * @inheritDoc
     */
    public function addWriteWatcher($stream, callable $callback): string
    {
        return $this->addStreamWatcher($stream, $callback, StreamWatcher::TYPE_WRITE);
    }

    /**
     * @inheritDoc
     */
    public function removeReadWatcher(string $watcherId): bool
    {
        if (!isset($this->watchers[$watcherId])) {
           return false;
        }
        
        $watcher = $this->watchers[$watcherId];
        
        if ($watcher->getType() !== StreamWatcher::TYPE_READ) {
            throw new InvalidArgumentException(
                "Watcher '{$watcherId}' is not a READ watcher, it is a {$watcher->getType()} watcher"
            );
        }
        
        return $this->removeStreamWatcher($watcherId);
    }

    /**
     * @inheritDoc
     */
    public function removeWriteWatcher(string $watcherId): bool
    {
        if (!isset($this->watchers[$watcherId])) {
            return false;
        }
        
        $watcher = $this->watchers[$watcherId];
        
        if ($watcher->getType() !== StreamWatcher::TYPE_WRITE) {
            throw new InvalidArgumentException(
                "Watcher '{$watcherId}' is not a WRITE watcher, it is a {$watcher->getType()} watcher"
            );
        }
        
        return $this->removeStreamWatcher($watcherId);
    }

    /**
     * @inheritDoc
     */
    public function addStreamWatcher($stream, callable $callback, string $type = StreamWatcher::TYPE_READ): string
    {
        $watcher = new StreamWatcher($stream, $callback, $type);
        $watcherId = $watcher->getId();
        $streamId = (int) $stream;
        
        $this->watchers[$watcherId] = $watcher;
        
        if ($type === StreamWatcher::TYPE_READ) {
            $this->readWatchers[$streamId] = $watcherId;
        } else {
            $this->writeWatchers[$streamId] = $watcherId;
        }

        return $watcherId;
    }

    /**
     * @inheritDoc
     */
    public function removeStreamWatcher(string $watcherId): bool
    {
        if (!isset($this->watchers[$watcherId])) {
            return false;
        }
        
        $watcher = $this->watchers[$watcherId];
        $stream = $watcher->getStream();
        
        if (\is_resource($stream)) {
            $streamId = (int) $stream;
            
            if ($watcher->getType() === StreamWatcher::TYPE_READ) {
                unset($this->readWatchers[$streamId]);
            } else {
                unset($this->writeWatchers[$streamId]);
            }
        }
        
        unset($this->watchers[$watcherId]);
        
        return true;
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
            $this->selectHandler->processReadyStreams(
                $readyStreams, 
                $this->watchers,
                $this->readWatchers,
                $this->writeWatchers
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
        $this->readWatchers = [];
        $this->writeWatchers = [];
    }
}