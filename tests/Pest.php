<?php

declare(strict_types=1);

use Hibla\EventLoop\EventLoopFactory;

beforeEach(function () {
    EventLoopFactory::reset();
});

afterEach(function () {
    EventLoopFactory::reset();
});


expect()->extend('toBeResource', function () {
    return $this->toBeResource();
});

expect()->extend('toBeValidTimestamp', function () {
    return $this->toBeFloat()
        ->toBeGreaterThan(0)
        ->toBeLessThan(time() + 3600)
    ;
});


function skipOnCI(): void
{
    if (getenv('CI')) {
        test()->markTestSkipped('Skipped on CI environment');
    }
}

function createTestStream()
{
    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        throw new RuntimeException('Failed to create test stream');
    }

    return $stream;
}


function runLoopFor(float $seconds): void
{
    $loop = EventLoopFactory::getInstance();

    $loop->addTimer($seconds, function () use ($loop) {
        $loop->stop();
    });

    $loop->run();
}