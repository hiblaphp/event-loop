<?php

declare(strict_types=1);

use Hibla\EventLoop\Managers\TimerManager;

describe('TimerManager', function () {
    it('starts with no timers', function () {
        $manager = new TimerManager();
        expect($manager->hasTimers())->toBeFalse();
    });

    it('can add and track timers', function () {
        $manager = new TimerManager();
        $timerId = $manager->addTimer(0.1, fn () => null);

        expect($timerId)->toBeString();
        expect($manager->hasTimers())->toBeTrue();
        expect($manager->hasTimer($timerId))->toBeTrue();
    });

    it('can cancel timers', function () {
        $manager = new TimerManager();
        $timerId = $manager->addTimer(0.1, fn () => null);

        expect($manager->cancelTimer($timerId))->toBeTrue();
        expect($manager->hasTimer($timerId))->toBeFalse();
        expect($manager->hasTimers())->toBeFalse();

        // Canceling non-existent timer returns false
        expect($manager->cancelTimer('invalid'))->toBeFalse();
    });

    it('executes ready timers', function () {
        $manager = new TimerManager();
        $executed = false;

        $manager->addTimer(0.001, function () use (&$executed) {
            $executed = true;
        });

        usleep(2000);
        $processed = $manager->processTimers();

        expect($processed)->toBeTrue();
        expect($executed)->toBeTrue();
        expect($manager->hasTimers())->toBeFalse(); // Timer should be removed after execution
    });

    it('handles periodic timers', function () {
        $manager = new TimerManager();
        $executed = 0;

        $timerId = $manager->addPeriodicTimer(0.001, function () use (&$executed) {
            $executed++;
        }, maxExecutions: 3);

        // Process multiple times
        for ($i = 0; $i < 5; $i++) {
            usleep(2000);
            $manager->processTimers();
        }

        expect($executed)->toBe(3);
        expect($manager->hasTimer($timerId))->toBeFalse(); // Should be removed after max executions
    });

    it('calculates next timer delay', function () {
        $manager = new TimerManager();

        // No timers
        expect($manager->getNextTimerDelay())->toBeNull();

        $manager->addTimer(0.1, fn () => null);
        $delay = $manager->getNextTimerDelay();

        expect($delay)->toBeFloat();
        expect($delay)->toBeGreaterThan(0);
        expect($delay)->toBeLessThanOrEqual(0.1);
    });

    it('provides timer statistics', function () {
        $manager = new TimerManager();

        $manager->addTimer(0.1, fn () => null);
        $manager->addPeriodicTimer(0.1, fn () => null, 5);

        $stats = $manager->getTimerStats();

        expect($stats)->toHaveKeys([
            'regular_timers',
            'periodic_timers',
            'total_timers',
            'total_periodic_executions',
        ]);

        expect($stats['regular_timers'])->toBe(1);
        expect($stats['periodic_timers'])->toBe(1);
        expect($stats['total_timers'])->toBe(2);
    });

    it('provides individual timer info', function () {
        $manager = new TimerManager();
        $timerId = $manager->addTimer(0.1, fn () => null);

        $info = $manager->getTimerInfo($timerId);

        expect($info)->toBeArray();
        expect($info)->toHaveKeys(['id', 'execute_at', 'is_ready', 'type']);
        expect($info['type'])->toBe('regular');

        // Non-existent timer
        expect($manager->getTimerInfo('invalid'))->toBeNull();
    });

    it('can clear all timers', function () {
        $manager = new TimerManager();

        $manager->addTimer(0.1, fn () => null);
        $manager->addPeriodicTimer(0.1, fn () => null);

        expect($manager->hasTimers())->toBeTrue();

        $manager->clearAllTimers();

        expect($manager->hasTimers())->toBeFalse();
    });
});
