<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Stream;

use Hibla\EventLoop\ValueObjects\StreamWatcher;

final readonly class StreamSelectHandler
{
    private const int MAX_TIMEOUT_MICROSECONDS = 5_000;

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
                    $except[] = $stream;
                }
            }
        }

        if (\count($read) === 0 && \count($write) === 0) {
            return [];
        }

        @stream_select($read, $write, $except, 0, self::MAX_TIMEOUT_MICROSECONDS);

        return [...$read, ...$write, ...$except];
    }

    /**
     * @param  array<resource>  $readyStreams  An array of stream resources that are ready.
     * @param  array<string, StreamWatcher>  &$watchers  The master map of active watchers, keyed by string ID.
     *                                                   This array is modified by reference.
     * @param  array<int, string>  &$readWatchers  Maps stream resource ID to READ watcher ID.
     *                                             This array is modified by reference.
     * @param  array<int, string>  &$writeWatchers  Maps stream resource ID to WRITE watcher ID.
     *                                              This array is modified by reference.
     */
    public function processReadyStreams(
        array $readyStreams, 
        array &$watchers,
        array &$readWatchers,
        array &$writeWatchers
    ): void {
        foreach ($readyStreams as $stream) {
            $streamId = (int) $stream;

            // Process read watcher if exists
            if (isset($readWatchers[$streamId])) {
                $watcherId = $readWatchers[$streamId];
                if (isset($watchers[$watcherId])) {
                    $watchers[$watcherId]->execute();
                }
            }

            // Process write watcher if exists
            if (isset($writeWatchers[$streamId])) {
                $watcherId = $writeWatchers[$streamId];
                if (isset($watchers[$watcherId])) {
                    $watchers[$watcherId]->execute();
                    
                    // Remove one-shot write watchers
                    unset($writeWatchers[$streamId]);
                    unset($watchers[$watcherId]);
                }
            }
        }
    }
}