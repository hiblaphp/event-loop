<?php

declare(strict_types=1);

use Hibla\EventLoop\EventLoop;

describe('EventLoop Shutdown', function () {
    it('stops gracefully', function () {
        $loop = EventLoop::getInstance();
        $executed = false;

        $loop->addTimer(0.001, function () use (&$executed, $loop) {
            $executed = true;
            $loop->stop();
        });

        $loop->run();

        expect($executed)->toBeTrue();
        expect($loop->isRunning())->toBeFalse();
    });

    it('handles force shutdown', function () {
        $loop = EventLoop::getInstance();

        $loop->addTimer(10.0, fn () => null);
        $loop->addPeriodicTimer(0.1, fn () => null);

        expect($loop->hasTimers())->toBeTrue();

        $loop->forceStop();

        expect($loop->isRunning())->toBeFalse();
    });

    it('cleans up resources on shutdown', function () {
        $loop = EventLoop::getInstance();

        $loop->addTimer(1.0, fn () => null);
        $loop->nextTick(fn () => null);
        $loop->defer(fn () => null);

        $stream = createTestStream();
        $loop->addStreamWatcher($stream, fn () => null);

        expect($loop->hasTimers())->toBeTrue();

        $loop->forceStop();

        expect($loop->isRunning())->toBeFalse();

        fclose($stream);
    });

    it('handles shutdown with pending HTTP requests', function () {
        $loop = EventLoop::getInstance();
        $completed = false;

        $loop->addHttpRequest('https://httpbin.org/delay/10', [], function () use (&$completed) {
            $completed = true;
        });

        $loop->forceStop();

        expect($loop->isRunning())->toBeFalse();
    });

    it('respects graceful shutdown timeout', function () {
        $loop = EventLoop::getInstance();
        $startTime = microtime(true);
        $periodicExecutions = 0;

        $loop->addPeriodicTimer(0.001, function () use (&$periodicExecutions) {
            $periodicExecutions++;
            usleep(1000);
        });

        $loop->addTimer(0.005, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        $duration = microtime(true) - $startTime;

        expect($duration)->toBeLessThan(2.0);

        expect($periodicExecutions)->toBeGreaterThan(0);
        expect($loop->isRunning())->toBeFalse();
    });
});
