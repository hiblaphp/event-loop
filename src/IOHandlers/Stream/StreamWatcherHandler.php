<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Stream;

use Hibla\EventLoop\ValueObjects\StreamWatcher;

final readonly class StreamWatcherHandler
{
    /**
     * @param  resource  $stream
     * @param  callable  $callback
     * @return StreamWatcher
     */
    public function createWatcher($stream, callable $callback, string $type = StreamWatcher::TYPE_READ): StreamWatcher
    {
        return new StreamWatcher($stream, $callback, $type);
    }

    public function executeWatcher(StreamWatcher $watcher): void
    {
        $watcher->execute();
    }
}
