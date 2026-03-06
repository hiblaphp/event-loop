<?php

declare(strict_types=1);

class UV
{
    public const int RUN_DEFAULT = 0;
    public const int RUN_ONCE = 1;
    public const int RUN_NOWAIT = 2;
    public const int READABLE = 1;
    public const int WRITABLE = 2;

    public const int SIGINT = 2;
    public const int SIGTERM = 15;
    public const int SIGHUP = 1;
    public const int SIGUSR1 = 10;
    public const int SIGUSR2 = 12;
}

class UVLoop
{
}

class UVHandle
{
}

class UVStream extends UVHandle
{
}
class UVTimer extends UVHandle
{
}
class UVSignal extends UVHandle
{
}
class UVPoll extends UVHandle
{
}

// ─── Loop Functions ───────────────────────────────────────────────────────────

/**
 * Returns the default event loop instance.
 */
function uv_default_loop(): UVLoop
{
}

/**
 * Creates and returns a new event loop.
 */
function uv_loop_new(): UVLoop
{
}

/**
 * Runs the event loop.
 *
 * @param UVLoop $loop The event loop to run.
 * @param int    $mode Run mode: UV::RUN_DEFAULT, UV::RUN_ONCE, or UV::RUN_NOWAIT.
 *
 * @return int Non-zero if there are still pending events.
 */
function uv_run(UVLoop $loop, int $mode = UV::RUN_DEFAULT): int
{
}

/**
 * Stops the event loop after the current iteration completes.
 *
 * @param UVLoop $loop The event loop to stop.
 */
function uv_stop(UVLoop $loop): void
{
}

// ─── Handle Functions ─────────────────────────────────────────────────────────

/**
 * Closes a UV handle and frees associated resources.
 * The optional callback is called asynchronously once the handle is fully closed.
 *
 * @param UVHandle      $handle   The handle to close.
 * @param callable|null $callback Called when fully closed: function(UVHandle $handle): void
 */
function uv_close(UVHandle $handle, ?callable $callback = null): void
{
}

/**
 * Returns whether the handle is currently active.
 *
 * @param UVHandle $handle The handle to check.
 */
function uv_is_active(UVHandle $handle): bool
{
}

/**
 * Returns whether the handle is closing or already closed.
 *
 * @param UVHandle $handle The handle to check.
 */
function uv_is_closing(UVHandle $handle): bool
{
}

// ─── Timer Functions ──────────────────────────────────────────────────────────

/**
 * Initializes a timer handle on the given event loop.
 *
 * @param UVLoop $loop The event loop to associate the timer with.
 *
 * @return UVTimer The initialized timer handle.
 */
function uv_timer_init(UVLoop $loop): UVTimer
{
}

/**
 * Starts a timer. The callback fires after $timeout ms, and repeats every $repeat ms if non-zero.
 *
 * @param UVTimer  $handle   The timer handle returned by uv_timer_init().
 * @param int      $timeout  Timeout in milliseconds before the first fire.
 * @param int      $repeat   Repeat interval in milliseconds (0 = one-shot).
 * @param callable $callback Invoked when the timer fires: function(UVTimer $handle): void
 */
function uv_timer_start(UVTimer $handle, int $timeout, int $repeat, callable $callback): void
{
}

/**
 * Stops the timer. No callback will be called.
 *
 * @param UVTimer $handle The active timer handle to stop.
 */
function uv_timer_stop(UVTimer $handle): void
{
}

// ─── Signal Functions ─────────────────────────────────────────────────────────

/**
 * Initializes a signal handle on the given event loop.
 *
 * @param UVLoop $loop The event loop to associate the signal handle with.
 *
 * @return UVSignal The initialized signal handle.
 */
function uv_signal_init(UVLoop $loop): UVSignal
{
}

/**
 * Starts watching for an OS signal on the given handle.
 *
 * @param UVSignal $handle   The signal handle returned by uv_signal_init().
 * @param callable $callback Invoked when the signal fires: function(UVSignal $handle, int $signum): void
 * @param int      $signal   The signal number to watch (e.g. UV::SIGINT, UV::SIGTERM).
 */
function uv_signal_start(UVSignal $handle, callable $callback, int $signal): void
{
}

/**
 * Stops the signal handle from watching for signals.
 *
 * @param UVSignal $handle The active signal handle to stop.
 */
function uv_signal_stop(UVSignal $handle): void
{
}

// ─── Poll Functions ───────────────────────────────────────────────────────────

/**
 * Initializes a poll handle for a socket resource.
 * Preferred over uv_poll_init() on Windows as it only supports sockets.
 *
 * @param UVLoop $loop   The event loop to associate the poll handle with.
 * @param mixed  $socket A PHP socket or stream resource to poll.
 *
 * @return UVPoll|false The initialized poll handle, or false on failure.
 */
function uv_poll_init_socket(UVLoop $loop, mixed $socket): UVPoll|false
{
}

/**
 * Initializes a poll handle for a file descriptor.
 *
 * @param UVLoop $loop   The event loop to associate the poll handle with.
 * @param mixed  $fd     A file descriptor or stream resource to poll.
 *
 * @return UVPoll|false The initialized poll handle, or false on failure.
 */
function uv_poll_init(UVLoop $loop, mixed $fd): UVPoll|false
{
}

/**
 * Starts polling the resource associated with the handle.
 *
 * @param UVPoll   $handle   The poll handle returned by uv_poll_init_socket() or uv_poll_init().
 * @param int      $events   Bitmask of events: UV::READABLE, UV::WRITABLE, or both.
 * @param callable $callback Invoked on activity: function(UVPoll $handle, int $status, int $events, mixed $fd): void
 */
function uv_poll_start(UVPoll $handle, int $events, callable $callback): void
{
}

/**
 * Stops the poll handle from watching for I/O events.
 *
 * @param UVPoll $handle The active poll handle to stop.
 */
function uv_poll_stop(UVPoll $handle): void
{
}
