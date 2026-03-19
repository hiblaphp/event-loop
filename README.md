# Hibla Event Loop

**The dual-driver, Node.js-style event loop engine powering the Hibla PHP ecosystem.**

A high-performance, cross-platform async event loop for PHP. Automatically uses
[ext-uv](https://github.com/amphp/ext-uv) (libuv) when available, falling back
to a pure PHP `stream_select` implementation with zero extra dependencies.
Designed as the foundation layer for higher-level Hibla packages or as the
foundation for other async ecosystems.

[![Latest Release](https://img.shields.io/github/release/hiblaphp/event-loop.svg?style=flat-square)](https://github.com/hiblaphp/event-loop/releases)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](./LICENSE)

---

## Contents

### Fundamentals
- [Installation](#installation)
- [Introduction](#introduction)
- [How the Loop Works](#how-the-loop-works)
- [Task Queues & the Work Phase](#task-queues--the-work-phase)
- [Auto-Run: Zero Boilerplate](#auto-run-zero-boilerplate)

### Core Features
- [Timers](#timers)
- [Async HTTP Curl Requests](#async-http-curl-requests)
- [Stream Watchers](#stream-watchers)
- [Fibers](#fibers)
- [Signal Handling](#signal-handling)

### Control and Configuration
- [Controlling the Loop](#controlling-the-loop)
- [Selecting a Driver](#selecting-a-driver)
- [Custom Loop Instance](#custom-loop-instance)

### Reference
- [Architecture](#architecture)
- [Development](#development)

---

## Installation
```bash
composer require hiblaphp/event-loop
```

**Requirements:**
- PHP 8.3+
- `ext-curl` (required only if you use `Loop::addCurlRequest()` — a `RuntimeException` is thrown at runtime if curl is not loaded)
- `ext-pcntl` + `ext-posix` (Unix/macOS only, required for signal handling)
- `ext-uv` (optional — enables the UV driver for better performance)

---

## Introduction

PHP has traditionally been synchronous — one line runs, finishes, and only
then does the next begin. Every blocking call, every database query, every HTTP
request holds the entire script hostage for its duration. This works fine for
short-lived request-response cycles, but falls apart the moment you need to
handle multiple things at once: waiting on ten HTTP responses, driving hundreds
of WebSocket connections, or running background jobs without spinning up a new
process for each one.

The solution is to invert the model. Instead of code waiting on I/O, you
register what should happen when I/O arrives and hand control back immediately.
A scheduler — the event loop — watches all the pending work at once, wakes up
exactly when something is ready, and dispatches the right callback. Between
events, the thread is free. No busy-spinning, no blocking, no wasted cycles.

PHP 8.1 Fibers make this model dramatically more powerful. A Fiber is a
pausable unit of execution that owns its own full call stack — it can suspend
from anywhere inside it, no matter how deeply nested, and be resumed exactly
where it left off. This means async code no longer has to be written as chains
of callbacks. A function can suspend deep inside your application, the entire
call stack freezes, the event loop picks up the next ready Fiber, and execution
resumes later as if nothing happened — code that reads top to bottom like
ordinary synchronous PHP, but runs cooperatively under the hood.

The loop itself follows Node.js phase semantics: each iteration runs through
signals, nextTick, microtasks, timers, I/O, fibers, check, and deferred — in
that order — with the nextTick and microtask queues fully drained after every
phase transition. Its I/O backend is selected at startup: when `ext-uv` is
available it delegates to libuv, using `epoll`, `kqueue`, or `IOCP` depending
on the platform; without it, a pure PHP `stream_select` implementation takes
over with no extra dependencies. Either way, the API is identical — the rest of
the Hibla ecosystem builds on it without knowing or caring which driver is
running underneath.

---

## How the Loop Works

Every iteration of the event loop runs through a fixed sequence of phases.
Understanding this order is the key to reasoning about when your callbacks
fire, why some work has higher priority than others, and how promises, timers,
and fibers interleave.
```
  Each iteration:

  1. Signal      dispatch any pending OS signals
       │
  2. nextTick    highest priority — drained completely before anything else
       │
  3. Microtask   promise resolution — drained after nextTick
       │
  4. Timers      ready timers fire one at a time
       │         nextTick + microtasks drain after each timer
       │
  5. I/O         stream watchers and HTTP requests
       │         nextTick + microtasks drain after I/O
       │
  6. Fibers      all ready fibers processed
       │         nextTick + microtasks drain after each fiber
       │
  7. Check       setImmediate() callbacks
       │         nextTick + microtasks drain after each callback
       │
  8. Deferred    runs only when phases 2–7 are all empty
```

The nextTick and microtask queues drain completely after every phase transition.
This is what keeps Promise resolution, timer callbacks, and Fiber resumption
predictable — high-priority callbacks are never starved by lower-priority work
accumulating in other phases.

---

## Task Queues & the Work Phase

### Phase order (per iteration)

| # | Phase | Method | Description |
|---|-------|--------|-------------|
| 1 | **Signal** | `Loop::addSignal()` | Dispatches any pending OS signals |
| 2 | **nextTick** | `Loop::nextTick()` | Highest priority. Drained completely before anything else |
| 3 | **Microtask** | `Loop::microTask()` | Runs after nextTick, before timers. Used internally for Promise resolution |
| 4 | **Timers** | `Loop::addTimer()` | Ready timers execute one at a time. nextTick + microtasks drain after each |
| 5 | **I/O** | streams, HTTP | `stream_select` / `uv_run` — wakes when I/O is ready or next timer is due |
| 6 | **Fibers** | `Loop::addFiber()` | All ready fibers are processed. nextTick + microtasks drain after each |
| 7 | **Check** | `Loop::setImmediate()` | Runs after I/O. New `setImmediate` calls during this phase land in the *next* iteration |
| 8 | **Deferred** | `Loop::defer()` | Runs only when phases 2–7 are all completely empty |

nextTick and microtask queues are drained **after every single phase
transition**, ensuring no high-priority callbacks are starved by
lower-priority work.

---

### `nextTick` — before everything else

`nextTick` callbacks have the highest priority in the loop. They run before
timers, before I/O, before fibers — before anything else in the next
iteration. The entire nextTick queue drains completely before the loop
advances to any other phase.

The practical use case is guaranteeing that a callback fires at the earliest
possible point in the next iteration, regardless of what other work is queued.
The most common real-world use is deferring work that depends on the current
synchronous call completing first.
```php
class EventEmitter
{
    private array $listeners = [];
    private array $pendingEvents = [];

    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function emit(string $event, mixed $data): void
    {
        // Defer emission to nextTick so the caller's synchronous code
        // finishes before any listener fires. Listeners attached after
        // emit() but before the next tick still receive the event.
        Loop::nextTick(function () use ($event, $data) {
            foreach ($this->listeners[$event] ?? [] as $listener) {
                $listener($data);
            }
        });
    }
}

$emitter = new EventEmitter();

$emitter->emit('data', 'hello'); // schedules emission — does NOT fire yet

// This listener is attached AFTER emit() — it still receives the event
// because emission is deferred to nextTick
$emitter->on('data', fn($d) => print("received: $d\n"));

// Output: received: hello
```

Another common use is breaking a large synchronous operation into chunks so
the loop stays responsive. Instead of processing everything in one blocking
call, you schedule the next chunk via `nextTick`, giving the loop a chance to
process I/O and timers between chunks:
```php
function processChunks(array $items): void
{
    if ($items === []) {
        return;
    }

    $chunk = array_splice($items, 0, 100);

    foreach ($chunk as $item) {
        process($item);
    }

    if ($items !== []) {
        // Schedule the next chunk — loop can handle I/O between chunks
        Loop::nextTick(fn() => processChunks($items));
    }
}
```

> **Warning:** A `nextTick` callback that schedules another `nextTick`
> indefinitely will starve the entire loop — timers, I/O, and fibers will
> never run. See the starvation section below.

---

### `microTask` — Promise resolution

`microTask` callbacks run after all `nextTick` callbacks in a given drain
cycle, but before timers and I/O. They exist specifically for Promise
resolution — when a Promise settles, its `.then()` callbacks are queued as
microtasks so they fire before any new I/O or timers get a turn.

You rarely need to call `Loop::microTask()` directly in application code.
It is used internally by `hiblaphp/promise` to schedule resolution callbacks.
If you are building your own Promise or Future implementation on top of the
event loop, queue resolution callbacks as microtasks:
```php
class Promise
{
    private array $thenCallbacks = [];
    private mixed $resolvedValue = null;
    private bool $resolved = false;

    public function then(callable $callback): static
    {
        if ($this->resolved) {
            // Already resolved — queue the callback as a microtask so it
            // fires before timers and I/O, matching Promise/A+ semantics
            Loop::microTask(fn() => $callback($this->resolvedValue));
        } else {
            $this->thenCallbacks[] = $callback;
        }

        return $this;
    }

    public function resolve(mixed $value): void
    {
        $this->resolved = true;
        $this->resolvedValue = $value;

        foreach ($this->thenCallbacks as $callback) {
            // Queue each resolution callback as a microtask — not nextTick,
            // not a timer. Microtasks fire after nextTick but before I/O,
            // which is the correct priority for promise resolution.
            Loop::microTask(fn() => $callback($value));
        }
    }
}
```

The distinction between `nextTick` and `microTask` matters when both are
queued in the same iteration:
```php
Loop::microTask(fn() => print("2 — microtask\n"));
Loop::nextTick(fn()  => print("1 — nextTick\n"));
Loop::microTask(fn() => print("3 — microtask\n"));

// Output:
// 1 — nextTick    ← nextTick always drains before microtasks
// 2 — microtask
// 3 — microtask
```

---

### `setImmediate` — after I/O, before the next iteration

`setImmediate` callbacks run in the **check phase** — after I/O has been
processed for the current iteration, but before the loop sleeps and waits for
the next event. This makes it the right tool when you want to do work that
responds to I/O results without delaying the next I/O poll.

The check phase uses a **queue swap** — any `setImmediate` call made *during*
the check phase lands in a fresh queue for the *next* iteration, not the
current one. This prevents check-phase callbacks from starving timers and I/O
by continuously scheduling new work into the same phase.
```php
// setImmediate fires after I/O in the current iteration
Loop::addReadWatcher($socket, function ($socket) {
    $data = fread($socket, 4096);

    // Process the data after all I/O for this iteration is done
    Loop::setImmediate(function () use ($data) {
        parseAndDispatch($data);
    });
});
```

A practical use case is batching work that arrives via multiple I/O events in
the same iteration. Instead of processing each event immediately, you
accumulate results and process them all together in `setImmediate`:
```php
$batch = [];

Loop::addReadWatcher($socket, function ($socket) use (&$batch) {
    $batch[] = fread($socket, 4096);

    // Schedule batch processing once — setImmediate is idempotent-safe
    // because new calls during check phase land in the next iteration
    Loop::setImmediate(function () use (&$batch) {
        processBatch($batch);
        $batch = [];
    });
});
```

The contrast with `nextTick` is important:
```
nextTick     — fires before I/O in the next iteration
setImmediate — fires after I/O in the current iteration
```

If you need work to happen as soon as possible, use `nextTick`. If you need
work to happen after the current round of I/O is done, use `setImmediate`.

---

### `defer` — when the loop is truly idle

`defer` callbacks run only when all other work is exhausted — the nextTick
queue is empty, the microtask queue is empty, no timers are ready, no I/O
is pending, no fibers are ready, and the check queue is empty. If any of
those phases have work, deferred callbacks wait.

Signal handlers are intentionally excluded from this check. A registered
signal listener is edge-triggered — it means "call me if this signal arrives",
not "there is pending work to do". A `SIGTERM` handler registered for graceful
shutdown may never fire on a normal exit. Treating it as pending work would
prevent deferred callbacks from ever running in long-lived processes that hold
signal listeners for their entire lifetime.
```php
Loop::addSignal(SIGTERM, fn() => Loop::stop()); // registered for lifetime of process

// This WILL run even though the SIGTERM listener is still registered —
// signals are not considered pending work for the purpose of defer
Loop::defer(function () {
    logger()->debug('Loop idle — all pending work complete');
});
```

This makes `defer` the right tool for work that should happen at the lowest
possible priority — cleanup, diagnostics, cache eviction, or anything that
should not compete with real work:
```php
// Flush metrics only when the loop has nothing else to do
Loop::defer(function () {
    metrics()->flush();
});
```

A common pattern is using `defer` for resource cleanup after a request
completes, ensuring cleanup never delays response handling:
```php
function handleRequest(Connection $conn): void
{
    $response = buildResponse();
    $conn->write($response);

    // Clean up only after everything else is done —
    // never delays the next request from being handled
    Loop::defer(fn() => $conn->cleanup());
}
```

The key distinction from `setImmediate`:
```
setImmediate — fires after I/O even if more timers and fibers are pending
defer        — fires only when there is genuinely nothing else left to do
              (signal handlers do not count as pending work)
```

---

### Choosing the right queue
```
Is the work urgent — must fire before any I/O or timers?
  └─► nextTick

Is this a Promise resolution callback?
  └─► microTask (or let hiblaphp/promise handle it automatically)

Should the work happen after the current round of I/O?
  └─► setImmediate

Is this cleanup or diagnostics that should never compete with real work?
  └─► defer
```

---

### nextTick starvation

Because the nextTick queue is drained completely before the loop advances to
any other phase, a nextTick callback that keeps enqueuing more nextTick
callbacks will starve timers, I/O, fibers, and all other work indefinitely.
The loop will never advance past phase 2 until the nextTick queue is empty.
```php
// ⚠ This will stall the event loop forever — timers and I/O will never run
Loop::nextTick(function () {
    Loop::nextTick(function () {
        Loop::nextTick(fn() => /* ... */);
    });
});
```

If you need to schedule recurring high-priority work without starving the loop,
use `Loop::setImmediate()` instead — it runs in the check phase after I/O and
new calls made during the check phase are deferred to the next iteration,
preventing starvation.

---

## Auto-Run: Zero Boilerplate

The event loop registers itself via `register_shutdown_function`. Any work
you schedule — timers, HTTP requests, fibers — will automatically be processed
when your script reaches the end, without you ever calling `Loop::run()`.
```php
use Hibla\EventLoop\Loop;

Loop::addTimer(1.0, function () {
    echo "Fired after 1 second\n";
});

// Script ends → loop runs automatically. No Loop::run() needed.
```

If you need to block and drive the loop explicitly mid-script, you can:
```php
Loop::run(); // Blocks until all work is exhausted or stop() is called
```

### Auto-run fires after all synchronous code finishes

The loop does not start until the current script has finished executing
top-level synchronous code. Work you schedule does not interrupt the script
— it waits until the script reaches the end and the shutdown function fires.
```php
Loop::nextTick(function () {
    echo "start\n";
});

echo "end\n";

// Output:
// end      ← synchronous code runs first
// start    ← loop starts after the script finishes, nextTick fires
```

This means you can safely schedule work anywhere in your script without
worrying about it running at an unexpected point. All synchronous setup
completes first, then the loop takes over.

> **Important:** Auto-run will **not** trigger if the script terminates
> abnormally. This includes unhandled exceptions, fatal errors (`E_ERROR`,
> `E_CORE_ERROR`, `E_COMPILE_ERROR`, etc.), or an explicit `exit()`/`die()`
> call. In these cases any pending work — timers, in-flight HTTP requests,
> queued fibers — will be silently abandoned.
>
> If your application relies on deferred work completing reliably, always
> call `Loop::run()` explicitly and handle exceptions before it is reached.
```php
// Safe pattern for scripts where completion matters
try {
    Loop::addTimer(1.0, fn() => doImportantWork());
    Loop::run();
} catch (\Throwable $e) {
    // Handle before the loop exits — auto-run will NOT save you here
    logger()->error($e->getMessage());
}
```

---

## Timers

### One-time timer

The callback receives no arguments. By the time it fires, the timer has already
been removed from the queue — there is nothing to cancel.
```php
Loop::addTimer(2.5, function () {
    echo "Runs once after 2.5 seconds\n";
});
```

### Repeating timer

The callback receives the timer's `string $timerId` as its first argument,
giving you a direct handle to cancel or inspect it from within the callback
itself without needing an outer variable.
```php
Loop::addPeriodicTimer(1.0, function (string $timerId) {
    echo "Tick — timer ID: $timerId\n";
});
```

### Repeating timer with a max execution count
```php
Loop::addPeriodicTimer(0.5, function () {
    echo "Runs 5 times, then stops\n";
}, maxExecutions: 5);
```

### Drift correction

Periodic timers use drift correction to keep their schedule stable. The next
fire time is always calculated from the *previous scheduled time*, not from
when the callback actually returned. If the system is briefly busy and a tick
fires late, the timer corrects back toward its original cadence on the next
tick rather than drifting forward permanently.
```
  Interval: 1.0s

  Without drift correction:          With drift correction:
  Tick 1: 1.000s                     Tick 1: 1.000s
  Tick 2: 2.050s  (+50ms late)       Tick 2: 2.050s  (+50ms late)
  Tick 3: 3.100s  (drift compounds)  Tick 3: 3.000s  (corrects back)
  Tick 4: 4.150s                     Tick 4: 4.000s
```

However, if a callback takes *longer than its own interval*, the loop does
not attempt to catch up on missed ticks — it resets the next fire time to
`now + interval` instead. A 100ms timer whose callback takes 300ms fires
again at 400ms, not at 200ms and 300ms.
```
  Interval: 100ms

  t=0ms    Tick 1 fires, callback starts
  t=300ms  Callback finishes (took 300ms — 2 ticks overdue)
  t=400ms  Tick 2 fires  ← resets from now, does NOT try to catch up
  t=500ms  Tick 3 fires
```

For most workloads — heartbeats, polling, metrics emission — this behavior is
exactly what you want. Missed ticks are dropped silently rather than flooding
the loop with catch-up work. If you need a guaranteed cadence where every tick
fires regardless of how long the previous one took, schedule the next tick
manually from within the callback instead:
```php
// Self-scheduling — next tick always fires 100ms after this one returns
$tick = null;
$tick = function () use (&$tick) {
    processItem();
    Loop::addTimer(0.1, $tick);
};

Loop::addTimer(0.1, $tick);
```

### Cancelling a one-time timer
```php
$id = Loop::addTimer(10.0, fn() => null);
Loop::cancelTimer($id);
```

### Cancelling a repeating timer

Periodic timers run indefinitely until cancelled. Use the returned ID to stop
them from outside the callback:
```php
$id = Loop::addPeriodicTimer(1.0, function () {
    echo "Tick\n";
});

// Stop after 5 seconds
Loop::addTimer(5.0, function () use ($id) {
    Loop::cancelTimer($id);
    echo "Periodic timer cancelled\n";
});
```

Or cancel from within its own callback using the injected `$timerId`:
```php
Loop::addPeriodicTimer(1.0, function (string $timerId) {
    static $count = 0;
    $count++;

    echo "Tick $count\n";

    if ($count >= 5) {
        Loop::cancelTimer($timerId);
    }
});
```

> **Note:** Self-cancellation via `$timerId` is useful when the stopping
> condition depends on runtime state rather than a fixed count. For a fixed
> count, `maxExecutions` is simpler.

---

## Async HTTP Curl Requests

> **Note:** `Loop::addCurlRequest()` is a low-level primitive that requires
> manual curl option management. For most use cases you should use
> [`hiblaphp/http-client`](https://github.com/hiblaphp/http-client) which
> provides a clean abstraction API built on top of this primitive.

`Loop::addCurlRequest()` accepts a URL, an array of `CURLOPT_*` options, and a
completion callback with the signature:
`(?string $error, ?string $body, ?int $httpCode, array $headers, ?string $httpVersion)`.
```php
Loop::addCurlRequest(
    url: 'https://api.example.com/data',
    options: [
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ],
    callback: function (?string $error, ?string $body, ?int $status, array $headers, ?string $version) {
        if ($error !== null) {
            echo "Request failed: $error\n";
            return;
        }

        echo "HTTP $status — Body: $body\n";
    }
);
```

### Forced curl options

The following options are always enforced internally and cannot be overridden
by the `$options` array, regardless of what you pass:

| Option | Value | Reason |
|---|---|---|
| `CURLOPT_URL` | The `$url` argument | Always derived from the first parameter |
| `CURLOPT_RETURNTRANSFER` | `true` | Required for the response body to be captured |
| `CURLOPT_HEADER` | `true` | Required for the response header parser to work |

### Callback parameters

| Parameter | Type | Description |
|---|---|---|
| `$error` | `?string` | curl error message, or `null` on success |
| `$body` | `?string` | Response body, or `null` on error |
| `$httpCode` | `?int` | HTTP status code, or `null` on error |
| `$headers` | `array` | Parsed associative array of response headers. Multi-value headers are represented as arrays |
| `$httpVersion` | `?string` | HTTP protocol version: `'1.0'`, `'1.1'`, `'2.0'`, `'3.0'`, or `null` if undetermined |

### Running requests concurrently

All requests registered before the loop ticks are admitted into `curl_multi`
together and processed concurrently. There is no special API needed —
just register multiple requests and they will run in parallel:
```php
Loop::addCurlRequest('https://api.example.com/users', [], fn(...$args) => handleUsers(...$args));
Loop::addCurlRequest('https://api.example.com/posts', [], fn(...$args) => handlePosts(...$args));
Loop::addCurlRequest('https://api.example.com/stats', [], fn(...$args) => handleStats(...$args));

// All three fire concurrently.
```

### Cancelling a request

Cancelling a request in flight calls the callback immediately with
`'Request cancelled'` as the `$error` argument:
```php
$id = Loop::addCurlRequest('https://...', [], fn() => null);
Loop::cancelCurlRequest($id);
```

---

## Stream Watchers

> **Note:** The stream watcher API is a low-level primitive. For most use
> cases — TCP servers, clients, pipes — you should use
> [`hiblaphp/stream`](https://github.com/hiblaphp/stream), which provides a
> high-level abstraction built on top of these primitives.

Stream watchers notify you when a stream resource is ready for reading or
writing. The stream **must** be set to non-blocking mode before registering a
watcher. The stream resource **must also remain open and valid** for the entire
lifetime of the watcher — closing a stream while a watcher is still registered
produces undefined behavior. Always remove the watcher before closing the
stream.
```php
$stream = stream_socket_client('tcp://example.com:80', $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT);
stream_set_blocking($stream, false);

$id = Loop::addReadWatcher($stream, function ($stream) {
    $data = fread($stream, 4096);
    echo "Received: $data\n";
});

// Always remove the watcher before closing
Loop::removeReadWatcher($id);
fclose($stream);
```
```php
$id = Loop::addWriteWatcher($stream, function ($stream) use (&$id) {
    fwrite($stream, "GET / HTTP/1.0\r\n\r\n");
    Loop::removeWriteWatcher($id);
});
```

If you have the watcher ID but not its type, `removeStreamWatcher()` handles
both:
```php
Loop::removeStreamWatcher($id);
```

### ext-uv limitation: file handles and in-memory streams

When running on the **UV driver**, only true socket or pipe resources are
supported as stream watchers. Passing a regular file handle (`fopen()`) or
an in-memory stream (`fopen('php://memory', ...)`, `fopen('php://temp', ...)`)
to `addReadWatcher()` or `addWriteWatcher()` will produce a PHP warning and
the watcher will silently fail to register.
```php
// These will cause a warning and fail silently on the UV driver:
$file   = fopen('/path/to/file.txt', 'r');
$memory = fopen('php://memory', 'r+');

Loop::addReadWatcher($file, $callback);   // ⚠ UV driver: unsupported handle type
Loop::addReadWatcher($memory, $callback); // ⚠ UV driver: unsupported handle type
```

This is a **libuv limitation** — `uv_poll` only operates on handles backed by
a real OS socket or pipe file descriptor. Regular files and virtual streams do
not have a pollable fd.

**Workarounds:**

- Force the `stream_select` driver for scripts that need to watch file handles:
```bash
HIBLA_LOOP_DRIVER=stream_select php your-script.php
```
- Read files synchronously or offload them to a worker process via
  [`hiblaphp/parallel`](https://github.com/hiblaphp/parallel) instead of
  watching them with the event loop.
- Use socket pairs or named pipes if you specifically need async
  inter-process communication on the UV driver.

The `stream_select` driver has no such restriction and supports all valid PHP
stream resources.

---

## Fibers

> **Note:** The fiber API is a low-level primitive primarily intended for
> building Promise, Future, and coroutine abstractions. If you are consuming
> Hibla's higher-level packages, you will likely interact with Promises rather
> than fibers directly.

### What is a Fiber?

PHP has traditionally been synchronous — code runs top to bottom, and every
line waits for the previous one to finish before moving on. If a line blocks
(waiting for a database query, an HTTP response, or a file read), the entire
script waits with it. There is no way to pause mid-function and let something
else run in the meantime.

Fibers, introduced in PHP 8.1, change this. A Fiber is a pausable function —
a block of code that can suspend itself mid-execution, hand control back to
whatever started it, and later be resumed exactly where it left off. Unlike a
regular function call, which runs to completion and returns once, a Fiber can
yield control multiple times before it finishes.
```php
$fiber = new Fiber(function () {
    echo "Step 1\n";
    Fiber::suspend(); // pause here, hand control back
    echo "Step 2\n"; // resumes from exactly this point later
});

$fiber->start();  // runs until the first suspend
echo "Between\n"; // runs while the fiber is paused
$fiber->resume(); // resumes the fiber from where it left off

// Output:
// Step 1
// Between
// Step 2
```

**Fibers are not inherently asynchronous.** A Fiber on its own is still
synchronous — `start()` and `resume()` are blocking calls that run the fiber
to its next suspend point before returning. Two fibers do not run concurrently
by themselves. Without a scheduler deciding when to start and resume each one,
a fiber that blocks on I/O simply blocks the entire thread, exactly as a plain
function call would.
```
  Without a scheduler — fibers block just like plain functions:

  Fiber A: fread()  ←─── blocks here, nothing else runs
  Fiber B:          ←─── never gets a turn until A unblocks


  With the event loop as scheduler — I/O is handed off, fibers interleave:

  Fiber A: await(readAsync()) ──► suspends, loop registers read watcher
  Fiber B: runs while A is waiting
  Fiber A: resumes when data arrives ──► continues from where it left off
```

The fiber is the mechanism. The event loop is what makes it async. But fibers
are still a better foundation for async code than plain callbacks — and the
reason comes down to one property: they are stackful.

### Fibers are stackful — unlike generators

PHP generators can also pause and resume, but they are **stackless** — a
generator can only suspend from the top level of its own body. It cannot
suspend from inside a function it called. This severely limits their
usefulness as an async primitive because any function that wants to pause
must itself be a generator, and every caller of that function must also be
a generator, all the way up the call stack. The suspension point must be
explicitly propagated through every layer.

Fibers have no such restriction. A Fiber is **stackful** — it owns its own
full call stack, and `Fiber::suspend()` can be called from anywhere within
that stack, no matter how deeply nested. An ordinary function called from
within a fiber, which itself calls another function, which calls another,
can suspend the entire fiber from that innermost frame. The whole call stack
is frozen in place and restored exactly when the fiber is resumed. None of
the intermediate callers need to know or care that a suspension happened.
```php
// Generators — suspension cannot cross function boundaries.
// Every layer must be a generator and explicitly yield upward.
function innerWork(): \Generator {
    yield; // can only suspend here because it is itself a generator
}

function outerWork(): \Generator {
    yield from innerWork(); // must explicitly propagate the yield
}

// Fibers — suspension works anywhere in the call stack, no propagation needed.
function innerWork(): void {
    Fiber::suspend(); // suspends the entire fiber from deep inside the stack
}

function middleLayer(): void {
    innerWork(); // has no idea a suspension might happen — and does not need to
}

function outerWork(): void {
    middleLayer(); // same — completely unaware of the suspension below
}

$fiber = new Fiber(function () {
    outerWork(); // fiber suspends inside innerWork(), deep in the call stack
    echo "Resumed — exactly where we left off\n";
});

$fiber->start();  // runs until Fiber::suspend() inside innerWork()
$fiber->resume(); // restores the entire call stack and continues
```

This is what makes fibers the right foundation for `await()`. A single
`Fiber::suspend()` call inside the deepest layer of your application can pause
the entire operation and hand control back to the event loop — without any of
the intermediate code needing to be rewritten as a generator or decorated with
`async`/`await` keywords. The suspension propagates upward through the call
stack automatically, invisibly, and completely.

### Cooperative scheduling model

Now that fibers can suspend from anywhere, something needs to decide when to
suspend them and when to resume them. That is the scheduler — and in Hibla,
the scheduler is the event loop.

Only **one fiber runs at a time**. The event loop does not run fibers in
parallel — it runs one fiber, waits for it to either suspend or terminate, then
moves on to the next. Fibers are cooperatively scheduled by the `FiberManager`,
meaning the loop only advances to the next fiber once the current one has
explicitly yielded control back via `Fiber::suspend()`. A fiber that never
suspends will run to completion before any other fiber in the queue gets a turn.
```php
Loop::addFiber(new Fiber(function () {
    echo "Fiber 1 — start\n";
    Fiber::suspend(); // yields control back to the loop
    echo "Fiber 1 — resumed\n";
}));

Loop::addFiber(new Fiber(function () {
    echo "Fiber 2 — start\n";
    Fiber::suspend();
    echo "Fiber 2 — resumed\n";
}));

// Output order:
// Fiber 1 — start       (fiber 1 runs until it suspends)
// Fiber 2 — start       (fiber 2 runs until it suspends)
// Fiber 1 — resumed     (fiber 1 is scheduled back and resumes)
// Fiber 2 — resumed     (fiber 2 is scheduled back and resumes)
```

This cooperative model is what makes fibers safe to use with shared state —
there are no race conditions because only one fiber is ever executing at a
given moment. The tradeoff is that a fiber which blocks or never suspends
monopolizes the loop until it finishes.

### How Fiber scheduling works

When you call `Loop::addFiber()`, the fiber is **not started immediately**.
It is placed in a ready queue and will be picked up during the **Fiber phase**
of the next event loop iteration. This means:

1. Fiber execution is always deferred to the next tick — you cannot rely on it running synchronously.
2. Fibers are started in the order they were added.
3. After each fiber is processed, the nextTick and microtask queues are fully drained before moving to the next fiber.
```php
$fiber = new Fiber(function () {
    echo "A — Fiber started (this does NOT print immediately)\n";
    Fiber::suspend();
    echo "C — Fiber resumed\n";
});

Loop::addFiber($fiber);
echo "B — This prints before the fiber starts\n";

// Output order:
// B — This prints before the fiber starts
// A — Fiber started
// C — Fiber resumed (once scheduleFiber is called)
```

---

### Suspending and resuming

Once a fiber calls `Fiber::suspend()`, it is moved to a suspended state
and will **never be automatically resumed**. You must explicitly call
`Loop::scheduleFiber()` to tell the event loop to resume it. This gives you
full control over when a suspended fiber is woken up — for example, after an
HTTP response arrives, a timer fires, or a stream becomes readable.
```php
$fiber = new Fiber(function () {
    echo "Fiber: waiting for data...\n";
    Fiber::suspend();
    echo "Fiber: resumed\n";
});

Loop::addFiber($fiber);

Loop::addTimer(1.0, function () use ($fiber) {
    Loop::scheduleFiber($fiber); // queues the fiber to resume in the next Fiber phase
});
```

`Loop::scheduleFiber()` queues the fiber back into the ready queue so it will
be picked up during the next Fiber phase. You can call it from anywhere —
a timer callback, a curl completion callback, a stream watcher, or another
fiber.

### Blocking calls stall the loop

Because fibers run on the same thread and only one fiber runs at a time,
a blocking call inside a fiber will stall the entire event loop for its
duration — no other timers, I/O watchers, or fibers will run until the
blocking call returns. Always ensure work inside a fiber is either
non-blocking or yields control via `Fiber::suspend()` while waiting.

### Building a Promise with Fibers

This is the primary intended use case. The three properties covered above
come together here: fibers suspend from any depth, the event loop resumes
them when I/O is ready, and the cooperative model ensures only one runs
at a time. A Promise implementation can suspend a fiber when awaiting a
result and resume it via `scheduleFiber()` when the value arrives — and
because fibers are stackful, the suspension happens deep inside your
application code without any of the intermediate layers needing to be
rewritten.

The basic primitive looks like this:
```php
function await(PromiseInterface $promise): mixed
{
    $fiber  = Fiber::getCurrent();
    $result = null;
    $error  = null;

    $promise
        ->then(static function ($value) use (&$result, $fiber) {
            $result = $value;
            Loop::scheduleFiber($fiber); // queues the fiber to resume after the promise resolves
        })
        ->catch(static function ($reason) use (&$error, $fiber) {
            $error = $reason;
            Loop::scheduleFiber($fiber); // queues the fiber to resume after the promise rejects
        })
    ;

    Fiber::suspend(); // suspends the entire fiber, however deep the call stack is

    if ($error !== null) {
        throw $error instanceof \Throwable
            ? $error
            : new \Exception('Promise rejected with: ' . var_export($error, true));
    }

    return $result;
}
```

And wrapping a callable in a fiber so it can use `await()` looks like this:
```php
function async(callable $function): PromiseInterface
{
    $promise = new Promise();

    $fiber = new Fiber(function () use ($function, $promise): void {
        try {
            $result = $function();
            $promise->resolve($result);
        } catch (\Throwable $e) {
            $promise->reject($e);
        }
    });

    Loop::addFiber($fiber);

    return $promise;
}
```

With these two primitives in place, async code reads like synchronous code —
`await()` suspends the fiber at any depth until the promise settles, the event
loop runs other work in the meantime, and execution resumes exactly where it
left off:
```php
$promise = async(function () {
    $user   = await(fetchUser(1));
    $orders = await(fetchOrders($user->id));
    return processOrders($orders);
});

$promise->then(fn($result) => print("Done: $result\n"));
```

No callbacks, no chaining — the fiber handles the suspension and resumption
transparently, while the event loop continues processing other timers, I/O,
and fibers while each `await()` is suspended.

### Key rules

- `Loop::addFiber()` — registers an **unstarted** fiber. It will be started
  during the next Fiber phase. If called after `Loop::forceStop()` the fiber
  is silently dropped.
- `Loop::scheduleFiber()` — queues a **suspended** fiber to be resumed by the
  event loop. The fiber must have been previously registered via `addFiber()`
  and reached a suspended state through the loop's own fiber phase. Calling
  `scheduleFiber()` on a fiber that was started outside the loop has no effect
  — it will never be resumed. Calling `scheduleFiber()` on a running or
  terminated fiber is silently ignored.
- A fiber only enters the loop's tracking after passing through `addFiber()`.
  The lifecycle is strictly linear — a fiber that bypasses `addFiber()` is
  invisible to the loop regardless of its suspended state:
```
addFiber() → readyQueue → processFibers() → suspendedFibers → scheduleFiber() → readyQueue
                                                    ↑
                          fibers started outside the loop never reach here
```

- Fibers that terminate normally are automatically cleaned up by the loop.
- A fiber that never calls `Fiber::suspend()` runs to completion before any
  other fiber in the queue gets a turn — see the cooperative scheduling model
  section above.

---

## Signal Handling

Signal handling is available on **Unix and macOS only** and requires `ext-pcntl`.
Calling `Loop::addSignal()` on Windows throws a `BadMethodCallException` because
`pcntl_signal` is not available on that platform.
```php
Loop::addSignal(SIGINT, function (int $signal) {
    echo "Caught SIGINT — shutting down gracefully...\n";
    Loop::stop();
});

Loop::addSignal(SIGTERM, function (int $signal) {
    Loop::stop();
});
```

Multiple independent listeners can be registered for the same signal number.
Each is assigned its own ID and can be removed individually without affecting
the others:
```php
$id1 = Loop::addSignal(SIGHUP, function (int $signal) {
    echo "Listener 1: reloading config...\n";
});

$id2 = Loop::addSignal(SIGHUP, function (int $signal) {
    echo "Listener 2: flushing cache...\n";
});

Loop::removeSignal($id1); // Only removes listener 1, listener 2 still active
```

---

## Controlling the Loop
```php
// Block until all work is exhausted or stop() is called
Loop::run();

// Process exactly one full iteration (all phases once), then invoke the
// sleep handler — this may block briefly if there is no immediate work
// but future timers are pending
Loop::runOnce();

// Graceful stop — finishes the current iteration then exits.
// Allows up to 10 additional iterations with a 2 second timeout for
// in-flight work to complete before forcing a shutdown.
Loop::stop();

// Immediate stop — clears all queues and exits now, no cleanup
Loop::forceStop();

// Introspection
Loop::isRunning(); // true while the loop is actively iterating
Loop::isIdle();    // true when no pending work or loop has been inactive
```

---

## Selecting a Driver

The UV driver is selected automatically when `ext-uv` is loaded. You can
override this with an environment variable:
```bash
# Force the pure PHP driver even if ext-uv is available
HIBLA_LOOP_DRIVER=stream_select php your-script.php

# Force the UV driver (throws RuntimeException if ext-uv is not loaded)
HIBLA_LOOP_DRIVER=uv php your-script.php
```

This environment variable is also useful in CI pipelines to run your test suite
against a specific driver without needing `ext-uv` installed:
```yaml
# Run tests against the pure PHP driver
- name: Run Tests
  run: HIBLA_LOOP_DRIVER=stream_select ./vendor/bin/pest
```

### How each driver works

**`stream_select` (pure PHP)**

The `stream_select` driver is built entirely on PHP's built-in `stream_select()`
function, which is a thin wrapper around the operating system's `select()`
syscall. Each iteration of the loop calls `stream_select()` with a calculated
timeout derived from the next pending timer, so it blocks efficiently until
either I/O arrives or the next timer is due — it does not busy-spin.

The key limitation of this driver comes from `select()` itself, not from PHP.
Most operating systems impose a hard cap of **1024 file descriptors** on a
single `select()` call (`FD_SETSIZE = 1024`). This means the `stream_select`
driver can watch at most **1024 simultaneous connections, streams, or file
handles** at one time. Attempting to exceed this limit produces undefined
behavior at the OS level.

Beyond the 1024 cap, `select()` also has an **O(N) readiness model**. Every
time `stream_select()` is called, the kernel must linearly scan through every
watched file descriptor to check which ones are ready. This means the more
connections you are watching, the more work the kernel does on every single
loop iteration — even if only one descriptor actually has activity. At low
connection counts this cost is negligible, but it grows linearly with load.

For the majority of applications — background jobs, scheduled tasks, moderate
HTTP workloads, CLI tooling — neither the 1024 limit nor the O(N) cost is ever
reached in practice, and `stream_select` is a perfectly capable driver with no
additional dependencies.

**`uv` (libuv via ext-uv)**

The UV driver delegates all I/O, timers, and signals to
[libuv](https://libuv.org) — the same event loop library that powers Node.js.
libuv uses `epoll` on Linux, `kqueue` on macOS, and `IOCP` on Windows under
the hood. These are modern kernel interfaces that scale to tens of thousands of
concurrent connections without the file descriptor cap that `select()` imposes.

libuv's underlying interfaces use an **O(1) readiness model**. Instead of
scanning all watched descriptors on every call, the kernel maintains an
internal interest list and delivers only the descriptors that have actual
activity. Whether you are watching 10 connections or 10,000, the cost of a
`uv_run` iteration does not grow with the number of idle watchers. This is the
fundamental scalability advantage of the UV driver over `stream_select` at high
connection counts.

Rather than polling for readiness in PHP-land, the UV driver registers a
`UVPoll` handle per stream and lets libuv notify it when activity occurs.
Timers are driven by a single master `UVTimer` handle, and signals are handled
natively inside `uv_run()` with no `pcntl_signal_dispatch()` call needed.

The tradeoff is that `ext-uv` must be compiled and installed separately, and —
as noted in the Stream Watchers section — libuv's `uv_poll` only supports true
socket and pipe file descriptors. Regular file handles and in-memory PHP
streams are not supported when using this driver.

### Driver comparison

| | `stream_select` | `uv` |
|---|---|---|
| **Dependency** | None (pure PHP) | `ext-uv` required |
| **I/O mechanism** | `select()` syscall | `epoll` / `kqueue` / `IOCP` via libuv |
| **Readiness model** | O(N) — kernel scans all watched fds | O(1) — kernel delivers only active fds |
| **Max concurrent streams** | ~1024 (OS `FD_SETSIZE` limit) | Tens of thousands |
| **File handle support** | All PHP stream resources | Sockets and pipes only |
| **In-memory streams** | `php://memory`, `php://temp` | Not supported |
| **Timers** | PHP-land `SplPriorityQueue` + `hrtime` | Single master `UVTimer` per loop |
| **Signals** | `pcntl_signal_dispatch()` each tick | `UVSignal` — native inside `uv_run` |
| **Sleep** | `time_nanosleep()` with retry logic | No-op — libuv sleeps natively |
| **Recommended for** | General use, low-to-moderate load | High-concurrency production workloads |

### Which should I use?

Use the **`stream_select` driver** if:
- You cannot or do not want to install `ext-uv`
- Your workload stays well under ~1024 concurrent connections
- You need to watch regular file handles or in-memory streams
- You are running background jobs, CLI tools, or moderate HTTP workloads

Use the **`uv` driver** if:
- You are building a high-concurrency server that handles thousands of simultaneous connections
- You want the most CPU-efficient event loop with the lowest latency
- You are on a platform where `ext-uv` is available and the socket-only stream restriction is acceptable

---

## Custom Loop Instance

For testing or custom implementations, you can swap out the instance behind
the `Loop` facade:
```php
use Hibla\Loop;
use Hibla\EventLoop\Interfaces\LoopInterface;

Loop::setInstance($myCustomLoop); // Must implement LoopInterface

Loop::setInstance(null); // Reset to the default singleton
```

For test isolation, use `Loop::reset()` between tests to fully tear down
the singleton and all registered shutdown hooks:
```php
protected function tearDown(): void
{
    Loop::reset();
}
```

> **Note:** `Loop::reset()` tears down the PHP-land singleton but does not
> cancel in-flight curl requests or close UV handles that may still be active.
> Tests that perform real I/O should explicitly call `Loop::forceStop()` before
> `Loop::reset()` to ensure resources are cleaned up before the next test runs.
```php
protected function tearDown(): void
{
    Loop::forceStop();
    Loop::reset();
}
```

---

## Architecture

The loop is built around a clean driver abstraction. The `Loop` static facade
delegates to an `EventLoopFactory` singleton, which uses
`EventLoopComponentFactory` to instantiate the correct driver components at
startup.

| Component | StreamSelect | UV |
|---|---|---|
| `WorkHandler` | Orchestrates `stream_select` + curl polling | Orchestrates `uv_run` + curl timer |
| `TimerManager` | `SplPriorityQueue` min-heap, PHP-land | Single master `UVTimer` per loop |
| `StreamManager` | `stream_select()` with timeout | `UVPoll` handle per stream |
| `SignalManager` | `pcntl_signal` + dispatch each tick | `UVSignal` handle per signal |
| `SleepHandler` | `time_nanosleep()` with retry | No-op (libuv sleeps natively) |

---

## Development

### Running tests

Clone the repository and install dependencies:
```bash
git clone https://github.com/hiblaphp/event-loop.git
cd event-loop
composer install
```

Run the test suite:
```bash
./vendor/bin/pest
```

Run against a specific driver:
```bash
HIBLA_LOOP_DRIVER=stream_select ./vendor/bin/pest
HIBLA_LOOP_DRIVER=uv ./vendor/bin/pest
```

Run static analysis:
```bash
./vendor/bin/phpstan analyse
```

---

## Credits

- **API Design:** Inspired by the [ReactPHP EventLoop](https://github.com/reactphp/event-loop) `Loop` API. If you are familiar with ReactPHP's loop interface, Hibla's API will feel immediately familiar — with the addition of native Fiber scheduling, built-in `curl_multi` integration, and a strict Node.js-style phase-based execution model.
- **Philosophy:** Inspired by Node.js event loop semantics and the libuv architecture.

---

## License

MIT License. See [LICENSE](./LICENSE) for more information.