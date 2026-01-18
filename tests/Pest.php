<?php

declare(strict_types=1);

use Hibla\EventLoop\EventLoopFactory;

expect()->extend('toBeResource', function () {
    return $this->toBeResource();
});

expect()->extend('toBeValidTimestamp', function () {
    return $this->toBeFloat()
        ->toBeGreaterThan(0)
        ->toBeLessThan(time() + 3600)
    ;
});

function createTestStream()
{
    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        throw new RuntimeException('Failed to create test stream');
    }

    return $stream;
}

function createTcpSocketPair(): array
{
    $server = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
    if (!$server) {
        throw new RuntimeException("Cannot create server socket: $errstr");
    }

    $name = stream_socket_get_name($server, false);
    preg_match('/:(\d+)$/', $name, $matches);
    $port = $matches[1];

    $client = stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 5);
    if (!$client) {
        fclose($server);
        throw new RuntimeException("Cannot create client socket: $errstr");
    }

    $serverConnection = stream_socket_accept($server, 5);
    if (!$serverConnection) {
        fclose($client);
        fclose($server);
        throw new RuntimeException("Cannot accept client connection");
    }

    fclose($server);

    return [$client, $serverConnection];
}

function runLoopFor(float $seconds): void
{
    $loop = EventLoopFactory::getInstance();

    $loop->addTimer($seconds, function () use ($loop) {
        $loop->stop();
    });

    $loop->run();
}
