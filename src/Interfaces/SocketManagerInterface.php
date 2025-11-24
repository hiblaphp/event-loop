<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Interfaces;

/**
 * Interface for managing socket watchers.
 */
interface SocketManagerInterface
{
    /**
     * Adds a callback for when socket is readable.
     *
     * @param  resource  $socket  The socket resource
     * @param  callable  $callback  The callback function
     */
    public function addReadWatcher(mixed $socket, callable $callback): void;

    /**
     * Adds a callback for when socket is writable.
     *
     * @param  resource  $socket  The socket resource
     * @param  callable  $callback  The callback function
     */
    public function addWriteWatcher(mixed $socket, callable $callback): void;

    /**
     * Removes all read watchers for a socket.
     *
     * @param  resource  $socket  The socket resource
     */
    public function removeReadWatcher(mixed $socket): void;

    /**
     * Removes all write watchers for a socket.
     *
     * @param  resource  $socket  The socket resource
     */
    public function removeWriteWatcher(mixed $socket): void;

    /**
     * Processes ready sockets and executes callbacks.
     *
     * @return bool True if any sockets were processed
     */
    public function processSockets(): bool;

    /**
     * Checks if there are any registered watchers.
     *
     * @return bool True if there are watchers
     */
    public function hasWatchers(): bool;

    /**
     * Clears all watchers for a specific socket.
     *
     * @param  resource  $socket  The socket resource
     */
    public function clearAllWatchersForSocket(mixed $socket): void;

    /**
     * Clears all socket watchers.
     */
    public function clearAllWatchers(): void;
}
