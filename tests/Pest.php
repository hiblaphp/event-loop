<?php

declare(strict_types=1);

use Hibla\EventLoop\EventLoop;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)->in('Feature', 'Integration', 'Performance');
pest()->extend(Tests\TestCase::class)->in('Unit');

beforeEach(function () {
    EventLoop::reset();
});

afterEach(function () {
    EventLoop::reset();
});

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeResource', function () {
    return $this->toBeResource();
});

expect()->extend('toBeValidTimestamp', function () {
    return $this->toBeFloat()
        ->toBeGreaterThan(0)
        ->toBeLessThan(time() + 3600)
    ;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can define your own functions.
|
*/

function skipOnCI(): void
{
    if (getenv('CI')) {
        test()->markTestSkipped('Skipped on CI environment');
    }
}

/**
 * Create a temporary stream resource for testing
 */
function createTestStream()
{
    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        throw new RuntimeException('Failed to create test stream');
    }

    return $stream;
}

function createTestSocketPair(): array
{
    if (PHP_OS_FAMILY !== 'Windows') {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets !== false) {
            return $sockets;
        }
    }

    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (! $server) {
        throw new RuntimeException("Failed to create server socket: $errstr");
    }

    $address = stream_socket_get_name($server, false);
    $client = stream_socket_client("tcp://$address", $errno, $errstr);
    if (! $client) {
        fclose($server);

        throw new RuntimeException("Failed to create client socket: $errstr");
    }

    $connection = stream_socket_accept($server);
    if (! $connection) {
        fclose($server);
        fclose($client);

        throw new RuntimeException('Failed to accept connection');
    }

    fclose($server);

    return [$client, $connection];
}

function createTestStreamPair(): array
{
    $pipes = [];
    $process = proc_open('cat', [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
    ], $pipes);

    if (! $process) {
        throw new RuntimeException('Failed to create process for stream testing');
    }

    return [$pipes[1], $pipes[0], $process];
}

/**
 * Create file-based stream pairs for testing
 */
function createTestFileStreams(): array
{
    $tempFile = tempnam(sys_get_temp_dir(), 'stream_test_');

    $writeStream = fopen($tempFile, 'w+');
    $readStream = fopen($tempFile, 'r');

    if (! $writeStream || ! $readStream) {
        throw new RuntimeException('Failed to create file streams');
    }

    return [$readStream, $writeStream, $tempFile];
}

/**
 * Run event loop for a specific duration
 */
function runLoopFor(float $seconds): void
{
    $loop = EventLoop::getInstance();

    $loop->addTimer($seconds, function () use ($loop) {
        $loop->stop();
    });

    $loop->run();
}

/**
 * Wait for condition with timeout
 */
function waitFor(callable $condition, float $timeout = 1.0): bool
{
    $startTime = microtime(true);
    while (microtime(true) - $startTime < $timeout) {
        if ($condition()) {
            return true;
        }
        usleep(1000);
    }

    return false;
}
