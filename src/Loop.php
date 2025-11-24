<?php

declare(strict_types=1);

namespace Hibla\EventLoop;

use Fiber;
use Hibla\EventLoop\Managers\TimerManager;
use Hibla\EventLoop\ValueObjects\StreamWatcher;

/**
 * Static convenience wrapper for the EventLoop singleton
 * Provides direct access to all EventLoop methods without getInstance() calls
 */
final class Loop
{
    /**
     * Get the singleton instance of the event loop.
     *
     * Creates a new instance if one doesn't exist, otherwise returns
     * the existing instance to ensure only one event loop runs per process.
     *
     * @return EventLoop The singleton event loop instance
     */
    public static function getInstance(): EventLoop
    {
        return EventLoop::getInstance();
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
        return EventLoop::getInstance()->addTimer($delay, $callback);
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
        return EventLoop::getInstance()->addPeriodicTimer($interval, $callback, $maxExecutions);
    }

    /**
     * Check if event loop has any pending timers.
     *
     * @return bool True if there are pending timers, false otherwise
     */
    public static function hasTimers(): bool
    {
        return EventLoop::getInstance()->hasTimers();
    }

    /**
     * Cancel a previously scheduled timer.
     *
     * @param  string  $timerId  The timer ID returned by addTimer()
     * @return bool True if timer was cancelled, false if not found
     */
    public static function cancelTimer(string $timerId): bool
    {
        return EventLoop::getInstance()->cancelTimer($timerId);
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
        return EventLoop::getInstance()->addHttpRequest($url, $options, $callback);
    }

    /**
     * Cancel a previously scheduled HTTP request.
     *
     * @param  string  $requestId  The request ID returned by addHttpRequest()
     * @return bool True if request was cancelled, false if not found
     */
    public static function cancelHttpRequest(string $requestId): bool
    {
        return EventLoop::getInstance()->cancelHttpRequest($requestId);
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
        return EventLoop::getInstance()->addStreamWatcher($stream, $callback, $type);
    }

    /**
     * Remove a stream watcher.
     *
     * @param  string  $watcherId  The watcher ID returned by addStreamWatcher()
     * @return bool True if watcher was removed, false if not found
     */
    public static function removeStreamWatcher(string $watcherId): bool
    {
        return EventLoop::getInstance()->removeStreamWatcher($watcherId);
    }

    /**
     * Add a fiber to be managed by the event loop.
     *
     * @param  Fiber<mixed, mixed, mixed, mixed>  $fiber  The fiber instance to add to the loop.
     */
    public static function addFiber(Fiber $fiber): void
    {
        EventLoop::getInstance()->addFiber($fiber);
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
        EventLoop::getInstance()->nextTick($callback);
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
        EventLoop::getInstance()->defer($callback);
    }

    /**
     * Start the main event loop execution.
     *
     * Continues processing work until the loop is stopped or no more
     * work is available. Includes forced shutdown to prevent hanging.
     */
    public static function run(): void
    {
        EventLoop::getInstance()->run();
    }

    /**
     * Force immediate stop of the event loop.
     *
     * This bypasses graceful shutdown and immediately clears all work.
     */
    public static function forceStop(): void
    {
        EventLoop::getInstance()->forceStop();
    }

    /**
     * Check if the event loop is currently running.
     *
     * @return bool True if the loop is running, false otherwise
     */
    public static function isRunning(): bool
    {
        return EventLoop::getInstance()->isRunning();
    }

    /**
     * Stop the event loop execution.
     *
     * Gracefully stops the event loop after the current iteration completes.
     * The loop will exit when it next checks the running state.
     */
    public static function stop(): void
    {
        EventLoop::getInstance()->stop();
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
        return EventLoop::getInstance()->isIdle();
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
        return EventLoop::getInstance()->addFileOperation($type, $path, $data, $callback, $options);
    }

    /**
     * Cancel a file operation
     *
     * @param  string  $operationId  The operation ID returned by addFileOperation()
     * @return bool True if operation was cancelled, false if not found
     */
    public static function cancelFileOperation(string $operationId): bool
    {
        return EventLoop::getInstance()->cancelFileOperation($operationId);
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
        return EventLoop::getInstance()->addFileWatcher($path, $callback, $options);
    }

    /**
     * Remove a file watcher
     *
     * @param  string  $watcherId  The watcher ID returned by addFileWatcher()
     * @return bool True if watcher was removed, false if not found
     */
    public static function removeFileWatcher(string $watcherId): bool
    {
        return EventLoop::getInstance()->removeFileWatcher($watcherId);
    }

    /**
     * Get current iteration count (useful for debugging/monitoring)
     *
     * @return int Current iteration count
     */
    public static function getIterationCount(): int
    {
        return EventLoop::getInstance()->getIterationCount();
    }

    /**
     * Get the timer manager.
     *
     * @return TimerManager The timer manager instance
     */
    public static function getTimerManager(): TimerManager
    {
        return EventLoop::getInstance()->getTimerManager();
    }

    /**
     * Resets the singleton instance. Primarily for testing purposes.
     */
    public static function reset(): void
    {
        EventLoop::reset();
    }
}
