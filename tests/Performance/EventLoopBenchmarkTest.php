<?php

declare(strict_types=1);

use Hibla\EventLoop\EventLoop;

describe('EventLoop Performance', function () {
    test('benchmarks timer performance', function () {
        $loop = EventLoop::getInstance();
        $timerCount = 1000;
        $executed = 0;

        $startTime = microtime(true);

        for ($i = 0; $i < $timerCount; $i++) {
            $loop->addTimer(0.001, function () use (&$executed) {
                $executed++;
            });
        }

        $loop->addTimer(0.1, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        $duration = microtime(true) - $startTime;
        $timersPerSecond = $executed / $duration;

        expect($executed)->toBe($timerCount);
        expect($timersPerSecond)->toBeGreaterThan(5000);

        echo "\nTimer Performance: {$timersPerSecond} timers/second\n";
    })->group('performance');

    test('benchmarks nextTick performance', function () {
        $loop = EventLoop::getInstance();
        $tickCount = 1000;
        $executed = 0;

        $startTime = microtime(true);

        for ($i = 0; $i < $tickCount; $i++) {
            $loop->nextTick(function () use (&$executed) {
                $executed++;
            });
        }

        $loop->addTimer(0.001, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        $duration = microtime(true) - $startTime;
        $ticksPerSecond = $executed / $duration;

        expect($executed)->toBe($tickCount);
        expect($ticksPerSecond)->toBeGreaterThan(5000);

        echo "\nNextTick Performance: {$ticksPerSecond} ticks/second\n";
    })->group('performance');

    test('benchmarks mixed workload performance', function () {
        $loop = EventLoop::getInstance();
        $counters = ['timers' => 0, 'ticks' => 0, 'deferred' => 0];

        $startTime = microtime(true);

        for ($i = 0; $i < 1000; $i++) {
            $loop->addTimer(0.001, function () use (&$counters) {
                $counters['timers']++;
            });

            $loop->nextTick(function () use (&$counters) {
                $counters['ticks']++;
            });

            $loop->defer(function () use (&$counters) {
                $counters['deferred']++;
            });
        }

        $loop->addTimer(0.1, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        $duration = microtime(true) - $startTime;
        $totalOps = array_sum($counters);
        $opsPerSecond = $totalOps / $duration;

        expect($totalOps)->toBe(3000);
        expect($opsPerSecond)->toBeGreaterThan(10000);

        echo "\nMixed Workload Performance: {$opsPerSecond} operations/second\n";
    })->group('performance');
})->group('performance');
