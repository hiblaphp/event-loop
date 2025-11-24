<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use Hibla\EventLoop\Interfaces\SocketManagerInterface;

/**
 * Manages socket watchers for an event loop using stream_select.
 *
 * - `$readWatchers` and `$writeWatchers` store callbacks associated with sockets.
 *   Structure: [ socket_resource_id (int) => [ [socket_resource, callback], ... ] ]
 */
class SocketManager implements SocketManagerInterface
{
    /**
     * Stores watchers for sockets ready to be read from.
     *
     * @var array<int, list<array{resource, callable}>>
     *                                                  Key: Socket resource ID (int)
     *                                                  Value: List of watcher arrays, each containing [socket_resource, callback]
     */
    private array $readWatchers = [];

    /**
     * Stores watchers for sockets ready to be written to.
     *
     * @var array<int, list<array{resource, callable}>>
     *                                                  Key: Socket resource ID (int)
     *                                                  Value: List of watcher arrays, each containing [socket_resource, callback]
     */
    private array $writeWatchers = [];

    /**
     * Adds a callback to be executed when the socket is readable.
     *
     * @param  resource  $socket  The stream socket resource.
     * @param  callable  $callback  The callback function.
     */
    public function addReadWatcher(mixed $socket, callable $callback): void // Using 'mixed' for resource as PHPStan level 6+ might require it if 'resource' is not fully supported in all contexts, otherwise 'resource' is preferred if your PHPStan config allows it.
    {
        $socketId = (int) $socket; // Cast resource to int for use as array key
        $this->readWatchers[$socketId][] = [$socket, $callback];
    }

    /**
     * Adds a callback to be executed when the socket is writable.
     *
     * @param  resource  $socket  The stream socket resource.
     * @param  callable  $callback  The callback function.
     */
    public function addWriteWatcher(mixed $socket, callable $callback): void
    {
        $socketId = (int) $socket; // Cast resource to int for use as array key
        $this->writeWatchers[$socketId][] = [$socket, $callback];
    }

    /**
     * Removes all read watchers for a specific socket.
     *
     * @param  resource  $socket  The stream socket resource.
     */
    public function removeReadWatcher(mixed $socket): void
    {
        $socketId = (int) $socket; // Cast resource to int for use as array key
        unset($this->readWatchers[$socketId]);
    }

    /**
     * Removes all write watchers for a specific socket.
     *
     * @param  resource  $socket  The stream socket resource.
     */
    public function removeWriteWatcher(mixed $socket): void
    {
        $socketId = (int) $socket; // Cast resource to int for use as array key
        unset($this->writeWatchers[$socketId]);
    }

    /**
     * Checks for sockets ready for I/O and processes their associated callbacks.
     *
     * @return bool True if any sockets were processed, false otherwise.
     */
    public function processSockets(): bool
    {
        if ($this->readWatchers === [] && $this->writeWatchers === []) {
            return false;
        }

        /** @var resource[] $readableStreams List of sockets to check for readability */
        $readableStreams = [];
        /** @var resource[] $writableStreams List of sockets to check for writability */
        $writableStreams = [];
        /** @var resource[]|null $exceptionStreams List of sockets to check for exceptions (not used) */
        $exceptionStreams = null;

        // Populate arrays for stream_select
        foreach ($this->readWatchers as $socketId => $watcherGroup) {
            $readableStreams[] = $watcherGroup[0][0];
        }

        foreach ($this->writeWatchers as $socketId => $watcherGroup) {
            $writableStreams[] = $watcherGroup[0][0];
        }

        $numChanged = @\stream_select($readableStreams, $writableStreams, $exceptionStreams, 0, 1000);

        // If no streams changed state or an error occurred
        if ($numChanged === false || $numChanged <= 0) {
            return false;
        }

        // Process callbacks for sockets that are ready for writing
        $this->processReadySockets($writableStreams, $this->writeWatchers);

        // Process callbacks for sockets that are ready for reading
        $this->processReadySockets($readableStreams, $this->readWatchers);

        return true;
    }

    /**
     * Invokes callbacks for sockets that are ready.
     *
     * @param  resource[]  $readySockets  List of stream resources that are ready (from stream_select).
     * @param  array<int, list<array{resource, callable}>>  &$watchers  Reference to the watchers array (read or write).
     */
    private function processReadySockets(array $readySockets, array &$watchers): void
    {
        foreach ($readySockets as $socket) {
            $socketId = (int) $socket;

            if (isset($watchers[$socketId])) {
                foreach ($watchers[$socketId] as [$_, $callback]) {
                    try {
                        $callback();
                    } catch (\Throwable $e) {
                        \error_log("Error in socket callback for ID {$socketId}: ".$e->getMessage());
                    }
                }

                unset($watchers[$socketId]);
            }
        }
    }

    /**
     * Checks if there are any registered watchers.
     *
     * @return bool True if there are watchers, false otherwise.
     */
    public function hasWatchers(): bool
    {
        return $this->readWatchers !== [] || $this->writeWatchers !== [];
    }

    /**
     * Removes all watchers (read and write) for a specific socket.
     *
     * @param  resource  $socket  The stream socket resource.
     */
    public function clearAllWatchersForSocket(mixed $socket): void
    {
        $socketId = (int) $socket;
        unset($this->readWatchers[$socketId], $this->writeWatchers[$socketId]);
    }

    /**
     * Clear all socket watchers.
     * Used during forced shutdown to prevent hanging.
     */
    public function clearAllWatchers(): void
    {
        $this->readWatchers = [];
        $this->writeWatchers = [];
    }
}
