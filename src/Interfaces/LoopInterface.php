<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Interfaces;

use Fiber;

interface LoopInterface
{
    /**
     * Schedules a callback to be executed after a delay.
     *
     * @param  float  $delay  Delay in seconds before execution
     * @param  callable  $callback  The callback to execute
     * @return string Unique timer ID that can be used to cancel the timer
     */
    public function addTimer(float $delay, callable $callback): string;

    /**
     * Schedule a periodic timer that executes repeatedly at specified intervals.
     *
     * @param  float  $interval  Interval in seconds between executions
     * @param  callable  $callback  Function to execute on each interval
     * @param  int|null  $maxExecutions  Maximum number of executions (null for infinite)
     * @return string Unique identifier for the periodic timer
     */
    public function addPeriodicTimer(float $interval, callable $callback, ?int $maxExecutions = null): string;

    /**
     * Cancel a previously scheduled timer.
     *
     * @param  string  $timerId  The timer ID returned by addTimer() or addPeriodicTimer()
     * @return bool True if timer was cancelled, false if not found
     */
    public function cancelTimer(string $timerId): bool;

    /**
     * Schedule an asynchronous HTTP request.
     *
     * @param  string  $url  The URL to request
     * @param  array<int, mixed>  $options  cURL options for the request, using CURLOPT_* constants.
     * @param  callable  $callback  Function to execute when request completes
     * @return string A unique ID for the request
     */
    public function addHttpRequest(string $url, array $options, callable $callback): string;

    /**
     * Cancel a previously scheduled HTTP request.
     *
     * @param  string  $requestId  The request ID returned by addHttpRequest()
     * @return bool True if request was cancelled, false if not found
     */
    public function cancelHttpRequest(string $requestId): bool;

    /**
     * Add a stream watcher for I/O operations.
     *
     * @param  resource  $stream  The stream resource to watch
     * @param  callable  $callback  Function to execute when stream has data
     * @param  string  $type  Type of stream operation (read/write)
     * @return string Unique identifier for the stream watcher
     */
    public function addStreamWatcher($stream, callable $callback, string $type = 'read'): string;

    /**
     * Remove a stream watcher.
     *
     * @param  string  $watcherId  The watcher ID returned by addStreamWatcher()
     * @return bool True if watcher was removed, false if not found
     */
    public function removeStreamWatcher(string $watcherId): bool;

    /**
     * @param  resource  $stream  The stream resource
     * @param  callable  $callback  The callback function
     * @return string The watcher ID
     */
    public function addReadWatcher($stream, callable $callback): string;

    /**
     * @param  resource  $stream  The stream resource
     * @param  callable  $callback  The callback function
     * @return string The watcher ID
     */
    public function addWriteWatcher($stream, callable $callback): string;

    /**
     * @param  resource  $stream  The stream resource
     * @return bool True if removed, false if not found
     */
    public function removeReadWatcher($stream): bool;

    /**
     * @param  resource  $stream  The stream resource
     * @return bool True if removed, false if not found
     */
    public function removeWriteWatcher($stream): bool;

    /**
     * Schedule an asynchronous file operation.
     *
     * @param  string  $type  Type of operation (read, write, append, etc.)
     * @param  string  $path  File path
     * @param  mixed  $data  Data for write operations
     * @param  callable  $callback  Function to execute when operation completes
     * @param  array<string, mixed>  $options  Additional options for the operation
     * @return string Unique identifier for the file operation
     */
    public function addFileOperation(
        string $type,
        string $path,
        mixed $data,
        callable $callback,
        array $options = []
    ): string;

    /**
     * Cancel a file operation.
     *
     * @param  string  $operationId  The operation ID returned by addFileOperation()
     * @return bool True if operation was cancelled, false if not found
     */
    public function cancelFileOperation(string $operationId): bool;

    /**
     * Add a file watcher to monitor file changes.
     *
     * @param  string  $path  Path to watch
     * @param  callable  $callback  Function to execute when file changes
     * @param  array<string, mixed>  $options  Additional options for watching
     * @return string Unique identifier for the file watcher
     */
    public function addFileWatcher(string $path, callable $callback, array $options = []): string;

    /**
     * Remove a file watcher.
     *
     * @param  string  $watcherId  The watcher ID returned by addFileWatcher()
     * @return bool True if watcher was removed, false if not found
     */
    public function removeFileWatcher(string $watcherId): bool;

    /**
     * Add a fiber to be managed by the event loop.
     *
     * @param  Fiber<mixed, mixed, mixed, mixed>  $fiber  The fiber instance to add to the loop
     */
    public function addFiber(Fiber $fiber): void;

    /**
     * Schedules a fiber for processing, moving it from the ready queue to the active queue.
     *
     * @param  Fiber<mixed, mixed, mixed, mixed>  $fiber  The fiber to schedule
     */
    public function scheduleFiber(Fiber $fiber): void;

    /**
     * Schedules a callback to run on the next tick of the event loop.
     *
     * Next tick callbacks have higher priority than timers and I/O operations.
     *
     * @param  callable  $callback  The callback to execute on next tick
     */
    public function nextTick(callable $callback): void;

    /**
     * Queue a microtask callback to run after nextTick but before timers.
     *
     * Microtasks are primarily used internally for Promise resolution callbacks.
     * They run after all nextTick callbacks but before any timer or I/O operations.
     *
     * @param  callable  $callback  Function to execute as a microtask
     */
    public function microTask(callable $callback): void;

    /**
     * Schedules a callback to run on the next check phase of the event loop.
     *
     * Check phase callbacks run after all nextTick and microtask callbacks.
     *
     * @param  callable  $callback  The callback to execute on next check phase
     */
    public function setImmediate(callable $callback): void;

    /**
     * Defers execution of a callback until the current call stack is empty.
     *
     * Similar to nextTick but with lower priority.
     *
     * @param  callable  $callback  The callback to defer
     */
    public function defer(callable $callback): void;

    /**
     * Starts the event loop and continues until stopped or no more operations.
     *
     * This method blocks until the event loop is explicitly stopped or
     * there are no more pending operations.
     */
    public function run(): void;

    /**
     * Run a single iteration of the event loop.
     * 
     * This processes one cycle of timers, I/O, and callbacks.
     * It will block (sleep) if there are no immediate tasks but pending future events.
     */
    public function runOnce(): void;

    /**
     * Stops the event loop from running.
     *
     * This will cause the run() method to return after completing
     * the current iteration.
     */
    public function stop(): void;

    /**
     * Force immediate stop of the event loop.
     *
     * This bypasses graceful shutdown and immediately clears all work.
     */
    public function forceStop(): void;

    /**
     * Check if the event loop is currently running.
     *
     * @return bool True if the loop is running, false otherwise
     */
    public function isRunning(): bool;

    /**
     * Checks if the event loop has no pending operations.
     *
     * @return bool True if the loop is idle (no pending operations), false otherwise
     */
    public function isIdle(): bool;

    /**
     * Register a listener to be notified when a signal has been caught by this process.
     *
     * This is useful to catch user interrupt signals or shutdown signals from
     * tools like supervisor or systemd.
     *
     * ```php
     * $loop->addSignal(SIGINT, function (int $signal) {
     *     echo "Caught user interrupt signal\n";
     *     $loop->stop();
     * });
     * ```
     *
     * Signaling is only available on Unix-like platforms with the pcntl extension.
     * Windows is not supported due to operating system limitations.
     * This method will throw a BadMethodCallException if signals aren't supported.
     *
     * @param int $signal The signal number (e.g., SIGINT, SIGTERM, SIGHUP)
     * @param callable(int): void $callback Function to execute when signal is received
     * @return string Unique identifier for this signal listener
     * @throws \BadMethodCallException If signals are not supported on this platform
     */
    public function addSignal(int $signal, callable $callback): string;

    /**
     * Remove a previously added signal listener.
     *
     * @param string $signalId The signal listener ID returned by addSignal()
     * @return bool True if listener was removed, false if not found
     */
    public function removeSignal(string $signalId): bool;
}
