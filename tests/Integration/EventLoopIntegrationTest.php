<?php

declare(strict_types=1);

use Hibla\EventLoop\EventLoopFactory;

describe('EventLoop Integration', function () {
    it('runs a complete event loop cycle', function () {
        $loop = EventLoopFactory::getInstance();
        $results = [];

        $loop->nextTick(function () use (&$results) {
            $results[] = 'nextTick';
        });

        $loop->addTimer(0.001, function () use (&$results) {
            $results[] = 'timer';
        });

        $loop->defer(function () use (&$results) {
            $results[] = 'deferred';
        });

        // Run for short duration
        runLoopFor(0.01);

        expect($results)->toContain('nextTick');
        expect($results)->toContain('timer');
        expect($results)->toContain('deferred');

        // NextTick should come before deferred
        expect(array_search('nextTick', $results))->toBeLessThan(array_search('deferred', $results));
    });

    it('handles multiple timers correctly', function () {
        $loop = EventLoopFactory::getInstance();
        $results = [];

        $loop->addTimer(0.001, function () use (&$results) {
            $results[] = 'timer1';
        });

        $loop->addTimer(0.002, function () use (&$results) {
            $results[] = 'timer2';
        });

        $loop->addTimer(0.003, function () use (&$results, $loop) {
            $results[] = 'timer3';
            $loop->stop();
        });

        $loop->run();

        expect($results)->toBe(['timer1', 'timer2', 'timer3']);
    });

    it('handles periodic timers', function () {
        $loop = EventLoopFactory::getInstance();
        $count = 0;

        $loop->addPeriodicTimer(0.001, function () use (&$count) {
            $count++;
        }, 5); // 5 executions max

        $loop->addTimer(0.01, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        expect($count)->toBe(5);
    });

    it('can cancel timers', function () {
        $loop = EventLoopFactory::getInstance();
        $executed = false;

        $timerId = $loop->addTimer(0.001, function () use (&$executed) {
            $executed = true;
        });

        // Cancel before execution
        $cancelled = $loop->cancelTimer($timerId);
        expect($cancelled)->toBeTrue();

        runLoopFor(0.01);

        expect($executed)->toBeFalse();
    });

    it('processes stream watchers', function () {
        $loop = EventLoopFactory::getInstance();
        $stream = createTestStream();
        $read = false;

        // Write some data
        fwrite($stream, 'test data');
        rewind($stream);

        $watcherId = $loop->addStreamWatcher($stream, function () use (&$read) {
            $read = true;
        });

        runLoopFor(0.01);

        expect($read)->toBeTrue();

        $loop->removeStreamWatcher($watcherId);
        fclose($stream);
    });

    it('handles fiber execution', function () {
        $loop = EventLoopFactory::getInstance();

        $fiber = new Fiber(function () {
            $value = Fiber::suspend('waiting');

            return "completed with $value";
        });

        $loop->addFiber($fiber);

        $loop->addTimer(0.001, function () use ($fiber) {
            if ($fiber->isSuspended()) {
                $fiber->resume('data');
            }
        });

        $startResult = $fiber->start();
        expect($startResult)->toBe('waiting');

        runLoopFor(0.01);

        $final = $fiber->isTerminated() ? $fiber->getReturn() : null;

        expect($final)->toBe('completed with data');
    });
});
