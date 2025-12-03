<?php

declare(strict_types=1);

use Hibla\EventLoop\EventLoopFactory;

describe('EventLoop Error Handling', function () {
    it('handles timer callback exceptions', function () {
        $loop = EventLoopFactory::getInstance();
        $goodCallbackExecuted = false;

        $loop->addTimer(0.001, function () {
            throw new Exception('Timer callback error');
        });

        $loop->addTimer(0.002, function () use (&$goodCallbackExecuted) {
            $goodCallbackExecuted = true;
        });

        $loop->addTimer(0.003, function () use ($loop) {
            $loop->stop();
        });

        // Should not throw, should continue processing
        $loop->run();

        expect($goodCallbackExecuted)->toBeTrue();
    });

    it('handles nextTick callback exceptions', function () {
        $loop = EventLoopFactory::getInstance();
        $executed = 0;

        $loop->nextTick(function () {
            throw new Exception('NextTick error');
        });

        $loop->nextTick(function () use (&$executed) {
            $executed++;
        });

        $loop->nextTick(function () use (&$executed) {
            $executed++;
        });

        $loop->addTimer(0.001, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        expect($executed)->toBe(2);
    });

    it('handles fiber exceptions', function () {
        $loop = EventLoopFactory::getInstance();
        $goodFiberCompleted = false;

        // Fiber that throws
        $badFiber = new Fiber(function () {
            throw new Exception('Fiber error');
        });

        // Good fiber
        $goodFiber = new Fiber(function () use (&$goodFiberCompleted) {
            $goodFiberCompleted = true;

            return 'success';
        });

        $loop->addFiber($badFiber);
        $loop->addFiber($goodFiber);

        $loop->addTimer(0.001, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        expect($goodFiberCompleted)->toBeTrue();
    });

    it('handles stream callback exceptions', function () {
        $loop = EventLoopFactory::getInstance();
        $stream = createTestStream();
        $goodCallbackExecuted = false;

        fwrite($stream, 'test data');
        rewind($stream);

        $loop->addStreamWatcher($stream, function () {
            throw new Exception('Stream callback error');
        });

        $loop->addStreamWatcher($stream, function () use (&$goodCallbackExecuted) {
            $goodCallbackExecuted = true;
        });

        $loop->addTimer(0.001, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        expect($goodCallbackExecuted)->toBeTrue();

        fclose($stream);
    });

    it('continues processing after resource cleanup errors', function () {
        $loop = EventLoopFactory::getInstance();
        $executed = false;

        // Create invalid resource scenario
        $stream = createTestStream();
        $loop->addStreamWatcher($stream, fn () => null);
        fclose($stream); // Close before processing

        $loop->addTimer(0.001, function () use (&$executed, $loop) {
            $executed = true;
            $loop->stop();
        });

        $loop->run();

        expect($executed)->toBeTrue();
    });

    it('handles memory pressure gracefully', function () {
        $loop = EventLoopFactory::getInstance();

        $operationCount = 100000;

        for ($i = 0; $i < $operationCount; $i++) {
            $loop->nextTick(function () {
                str_repeat('x', 1000);
            });
        }

        $startMemory = memory_get_usage();
        $loop->run();
        $endMemory = memory_get_usage();

        expect($endMemory - $startMemory)->toBeLessThan(50 * 1024 * 1024);
    });
});
