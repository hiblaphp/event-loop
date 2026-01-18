<?php

declare(strict_types=1);

namespace Hibla\EventLoop;

use Fiber;
use Hibla\EventLoop\Interfaces\LoopInterface;
use Hibla\EventLoop\ValueObjects\StreamWatcher;

/**
 * Static convenience wrapper for the EventLoop singleton
 * Provides direct access to all EventLoop methods without getInstance() calls
 */
final class Loop
{
    /**
     * Custom loop instance (if set)
     */
    private static ?LoopInterface $customInstance = null;

    /**
     * Get the singleton instance of the event loop.
     *
     * Creates a new instance if one doesn't exist, otherwise returns
     * the existing instance to ensure only one event loop runs per process.
     *
     * @return LoopInterface The event loop instance
     */
    public static function getInstance(): LoopInterface
    {
        if (self::$customInstance !== null) {
            return self::$customInstance;
        }

        return EventLoopFactory::getInstance();
    }

    /**
     * Set a custom event loop instance.
     *
     * This allows replacing the default EventLoop with a custom implementation.
     * Useful for testing or providing alternative event loop implementations.
     *
     * @param LoopInterface|null $instance The custom loop instance, or null to reset to default
     */
    public static function setInstance(?LoopInterface $instance): void
    {
        self::$customInstance = $instance;
    }

    /**
     * Register a listener to be notified when a signal has been caught.
     *
     * @param int $signal The signal number (e.g., SIGINT, SIGTERM)
     * @param callable $callback Function to execute when signal is received
     * @return string Unique identifier for this signal listener
     * @throws \BadMethodCallException If signals are not supported
     */
    public static function addSignal(int $signal, callable $callback): string
    {
        return self::getInstance()->addSignal($signal, $callback);
    }

    /**
     * Remove a signal listener.
     *
     * @param string $signalId The signal listener ID returned by addSignal()
     * @return bool True if listener was removed, false if not found
     */
    public static function removeSignal(string $signalId): bool
    {
        return self::getInstance()->removeSignal($signalId);
    }

    /**
     * Schedule a timer to execute a callback after a specified delay.
     *
     * @param  float  $delay  Delay in seconds before executing the callback
     * @param  callable  $callback  Function to execute when timer expires
     * @return string Unique identifier for the timer
     */
    public static function addTimer(float $delay, callable $callback): string
    {
        return self::getInstance()->addTimer($delay, $callback);
    }

    /**
     * Schedule a periodic timer that executes repeatedly at specified intervals.
     *
     * @param  float  $interval  Interval in seconds between executions
     * @param  callable  $callback  Function to execute on each interval
     * @param  int|null  $maxExecutions  Maximum number of executions (null for infinite)
     * @return string Unique identifier for the periodic timer
     */
    public static function addPeriodicTimer(float $interval, callable $callback, ?int $maxExecutions = null): string
    {
        return self::getInstance()->addPeriodicTimer($interval, $callback, $maxExecutions);
    }

    /**
     * Cancel a previously scheduled timer.
     *
     * @param  string  $timerId  The timer ID returned by addTimer()
     * @return bool True if timer was cancelled, false if not found
     */
    public static function cancelTimer(string $timerId): bool
    {
        return self::getInstance()->cancelTimer($timerId);
    }

    /**
     * Schedule an asynchronous HTTP request.
     *
     * @param  string  $url  The URL to request.
     * @param  array<int, mixed>  $options  cURL options for the request, using CURLOPT_* constants as keys.
     * @param  callable  $callback  Function to execute when request completes.
     * @return string A unique ID for the request.
     */
    public static function addHttpRequest(string $url, array $options, callable $callback): string
    {
        return self::getInstance()->addHttpRequest($url, $options, $callback);
    }

    /**
     * Cancel a previously scheduled HTTP request.
     *
     * @param  string  $requestId  The request ID returned by addHttpRequest()
     * @return bool True if request was cancelled, false if not found
     */
    public static function cancelHttpRequest(string $requestId): bool
    {
        return self::getInstance()->cancelHttpRequest($requestId);
    }

    /**
     * Add a stream watcher for I/O operations.
     *
     * @param  resource  $stream  The stream resource to watch
     * @param  callable  $callback  Function to execute when stream has data
     * @param  string  $type  Type of stream watching (read/write)
     * @return string Unique identifier for the stream watcher
     */
    public static function addStreamWatcher($stream, callable $callback, string $type = StreamWatcher::TYPE_READ): string
    {
        return self::getInstance()->addStreamWatcher($stream, $callback, $type);
    }

    /**
     * Remove a stream watcher.
     *
     * @param  string  $watcherId  The watcher ID returned by addStreamWatcher()
     * @return bool True if watcher was removed, false if not found
     */
    public static function removeStreamWatcher(string $watcherId): bool
    {
        return self::getInstance()->removeStreamWatcher($watcherId);
    }

    /**
     * Add a watcher for read operations on a stream.
     *
     * @param  resource  $stream  The stream resource to watch for reads
     * @param  callable  $callback  Function to execute when stream has data to read
     * 
     * @return string Unique identifier for the read watcher
     */
    public static function addReadWatcher($stream, callable $callback): string
    {
        return self::getInstance()->addReadWatcher($stream, $callback);
    }

    /**
     * Add a watcher for write operations on a stream.
     *
     * @param  resource  $stream  The stream resource to watch for writes
     * @param  callable  $callback  Function to execute when stream is ready for writing
     * @return string Unique identifier for the write watcher
     */
    public static function addWriteWatcher($stream, callable $callback): string
    {
        return self::getInstance()->addWriteWatcher($stream, $callback);
    }

    /**
     * Remove a watcher for read operations on a stream.
     *
     * @param  string  $readWatcherId  The read watcher ID to remove
     * @return bool True if watcher was removed, false if not found
     */
    public static function removeReadWatcher(string $readWatcherId): bool
    {
        return self::getInstance()->removeReadWatcher($readWatcherId);
    }

    /**
     * Remove a watcher for write operations on a stream.
     *
     * @param  string  $writeWatcherId  The write watcher ID to remove
     * @return bool True if watcher was removed, false if not found
     */
    public static function removeWriteWatcher(string $writeWatcherId): bool
    {
        return self::getInstance()->removeWriteWatcher($writeWatcherId);
    }

    /**
     * Add a fiber to be managed by the event loop.
     *
     * @param  Fiber<mixed, mixed, mixed, mixed>  $fiber  The fiber instance to add to the loop.
     */
    public static function addFiber(Fiber $fiber): void
    {
        self::getInstance()->addFiber($fiber);
    }

    /**
     * Schedules a fiber for processing, moving it from the ready queue to the active queue.
     *
     * @param  Fiber<mixed, mixed, mixed, mixed>  $fiber  The fiber to schedule
     */
    public static function scheduleFiber(Fiber $fiber): void
    {
        self::getInstance()->scheduleFiber($fiber);
    }

    /**
     * Schedule a callback to run on the next event loop tick.
     *
     * Next-tick callbacks have the highest priority and execute before
     * any other work in the next loop iteration.
     *
     * @param  callable  $callback  Function to execute on next tick
     */
    public static function nextTick(callable $callback): void
    {
        self::getInstance()->nextTick($callback);
    }

    /**
     * Schedule a microtask to run after the current work phase.
     *
     * Microtasks are processed after all immediate work and before
     * any timers or fibers. Use for short, high-priority tasks.
     *
     * @param  callable  $callback  Function to execute as a microtask
     */
    public static function microTask(callable $callback): void
    {
        self::getInstance()->microTask($callback);
    }

    /**
     * Schedules a callback to run on the next check phase of the event loop.
     *
     * Check phase callbacks run after all nextTick and microtask callbacks.
     *
     * @param  callable  $callback  The callback to execute on next check phase
     */
    public static function setImmediate(callable $callback): void
    {
        self::getInstance()->setImmediate($callback);
    }

    /**
     * Schedule a callback to run after the current work phase.
     *
     * Deferred callbacks run after all immediate work is processed
     * but before the loop sleeps or waits for events.
     *
     * @param  callable  $callback  Function to execute when deferred
     */
    public static function defer(callable $callback): void
    {
        self::getInstance()->defer($callback);
    }


    /**
     * Run a single iteration of the event loop.
     * 
     * This processes one cycle of timers, I/O, and callbacks.
     * It will block (sleep) if there are no immediate tasks but pending future events.
     */
    public static function runOnce(): void
    {
        self::getInstance()->runOnce();
    }

    /**
     * Start the main event loop execution.
     *
     * Continues processing work until the loop is stopped or no more
     * work is available. Includes forced shutdown to prevent hanging.
     */
    public static function run(): void
    {
        self::getInstance()->run();
    }

    /**
     * Force immediate stop of the event loop.
     *
     * This bypasses graceful shutdown and immediately clears all work.
     */
    public static function forceStop(): void
    {
        self::getInstance()->forceStop();
    }

    /**
     * Check if the event loop is currently running.
     *
     * @return bool True if the loop is running, false otherwise
     */
    public static function isRunning(): bool
    {
        return self::getInstance()->isRunning();
    }

    /**
     * Stop the event loop execution.
     *
     * Gracefully stops the event loop after the current iteration completes.
     * The loop will exit when it next checks the running state.
     */
    public static function stop(): void
    {
        self::getInstance()->stop();
    }

    /**
     * Check if the event loop is currently idle.
     *
     * An idle loop has no pending work or has been inactive for an
     * extended period. Useful for determining system load state.
     *
     * @return bool True if the loop is idle, false if actively processing
     */
    public static function isIdle(): bool
    {
        return self::getInstance()->isIdle();
    }

    /**
     * Schedule an asynchronous file operation
     *
     * @param  string  $type  Type of file operation
     * @param  string  $path  File path
     * @param  mixed  $data  Data for the operation
     * @param  callable  $callback  Function to execute when operation completes
     * @param  array<string, mixed>  $options  Additional options
     * @return string Unique identifier for the file operation
     */
    public static function addFileOperation(
        string $type,
        string $path,
        mixed $data,
        callable $callback,
        array $options = []
    ): string {
        return self::getInstance()->addFileOperation($type, $path, $data, $callback, $options);
    }

    /**
     * Cancel a file operation
     *
     * @param  string  $operationId  The operation ID returned by addFileOperation()
     * @return bool True if operation was cancelled, false if not found
     */
    public static function cancelFileOperation(string $operationId): bool
    {
        return self::getInstance()->cancelFileOperation($operationId);
    }

    /**
     * Add a file watcher
     *
     * @param  string  $path  File path to watch
     * @param  callable  $callback  Function to execute when file changes
     * @param  array<string, mixed>  $options  Watcher options
     * @return string Unique identifier for the file watcher
     */
    public static function addFileWatcher(string $path, callable $callback, array $options = []): string
    {
        return self::getInstance()->addFileWatcher($path, $callback, $options);
    }

    /**
     * Remove a file watcher
     *
     * @param  string  $watcherId  The watcher ID returned by addFileWatcher()
     * @return bool True if watcher was removed, false if not found
     */
    public static function removeFileWatcher(string $watcherId): bool
    {
        return self::getInstance()->removeFileWatcher($watcherId);
    }

    /**
     * Resets the singleton instance. Primarily for testing purposes.
     */
    public static function reset(): void
    {
        self::$customInstance = null;
        EventLoopFactory::reset();
    }
}
