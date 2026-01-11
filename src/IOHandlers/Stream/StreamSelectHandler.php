<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Stream;

use Hibla\EventLoop\ValueObjects\StreamWatcher;

final readonly class StreamSelectHandler
{
    private const int MAX_TIMEOUT_MICROSECONDS = 1_000;

    /**
     * @param  array<string, StreamWatcher>  $watchers
     * @return array<resource>
     */
    public function selectStreams(array $watchers): array
    {
        if (\count($watchers) === 0) {
            return [];
        }

        $read = $write = $except = [];
        foreach ($watchers as $watcher) {
            $stream = $watcher->getStream();
            if (\is_resource($stream)) {
                if ($watcher->getType() === StreamWatcher::TYPE_READ) {
                    $read[] = $stream;
                } elseif ($watcher->getType() === StreamWatcher::TYPE_WRITE) {
                    $write[] = $stream;
                }
            }
        }

        if (\count($read) === 0 && count($write) === 0) {
            return [];
        }

        @stream_select($read, $write, $except, 0, self::MAX_TIMEOUT_MICROSECONDS);

        return array_merge($read, $write);
    }

    /**
     * @param  array<resource>  $readyStreams  An array of stream resources that are ready.
     * @param  array<string, StreamWatcher>  &$watchers  The master map of active watchers, keyed by string ID.
     *                                                   This array is modified by reference.
     */
    public function processReadyStreams(array $readyStreams, array &$watchers): void
    {
        $lookupMap = [];
        foreach ($watchers as $watcherId => $watcher) {
            $stream = $watcher->getStream();
            if (\is_resource($stream)) {
                $lookupMap[(int) $stream] = $watcherId;
            }
        }

        foreach ($readyStreams as $stream) {
            $socketId = (int) $stream;

            if (isset($lookupMap[$socketId])) {
                $watcherId = $lookupMap[$socketId];
                // Ensure the watcher still exists in the master list before processing.
                if (isset($watchers[$watcherId])) {
                    $watcher = $watchers[$watcherId];
                    $watcher->execute();
                    // If the watcher is a one-shot (like a WRITE), remove it.
                    if ($watcher->getType() === StreamWatcher::TYPE_WRITE) {
                        unset($watchers[$watcherId]);
                    }
                }
            }
        }
    }
}
