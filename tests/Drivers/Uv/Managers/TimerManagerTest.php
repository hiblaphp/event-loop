<?php

declare(strict_types=1);

use Hibla\EventLoop\Drivers\Uv\Managers\TimerManager;

describe('TimerManager (UV)', function () {
    it('starts with no timers', function () {
        $loop = \uv_loop_new();
        $manager = new TimerManager($loop);

        expect($manager->hasTimers())->toBeFalse();
    });

    it('can add and track timers', function () {
        $loop = \uv_loop_new();
        $manager = new TimerManager($loop);

        $timerId = $manager->addTimer(0.1, fn () => null);

        expect($timerId)->toBeString();
        expect($manager->hasTimers())->toBeTrue();
        expect($manager->hasTimer($timerId))->toBeTrue();
    });

    it('can cancel timers', function () {
        $loop = \uv_loop_new();
        $manager = new TimerManager($loop);

        $timerId = $manager->addTimer(0.1, fn () => null);

        expect($manager->cancelTimer($timerId))->toBeTrue();
        expect($manager->hasTimer($timerId))->toBeFalse();
        expect($manager->hasTimers())->toBeFalse();

        expect($manager->cancelTimer('invalid'))->toBeFalse();
    });

    it('executes ready timers via collectReadyTimers', function () {
        $loop = \uv_loop_new();
        $manager = new TimerManager($loop);
        $executed = false;

        $manager->addTimer(0.001, function () use (&$executed) {
            $executed = true;
        });

        \uv_run($loop, UV::RUN_ONCE);

        $callbacks = $manager->collectReadyTimers();

        expect($callbacks)->not->toBeEmpty();

        foreach ($callbacks as $callback) {
            $callback();
        }

        expect($executed)->toBeTrue();
        expect($manager->hasTimers())->toBeFalse();
    });

    it('handles periodic timers', function () {
        $loop = \uv_loop_new();
        $manager = new TimerManager($loop);
        $executed = 0;

        $timerId = $manager->addPeriodicTimer(0.001, function () use (&$executed) {
            $executed++;
        }, maxExecutions: 3);

        for ($i = 0; $i < 5; $i++) {
            \uv_run($loop, UV::RUN_ONCE);
            $callbacks = $manager->collectReadyTimers();
            foreach ($callbacks as $callback) {
                $callback();
            }
            $manager->rescheduleMaster();
        }

        expect($executed)->toBe(3);
        expect($manager->hasTimer($timerId))->toBeFalse();
    });

    it('calculates next timer delay', function () {
        $loop = \uv_loop_new();
        $manager = new TimerManager($loop);

        expect($manager->getNextTimerDelay())->toBeNull();

        $manager->addTimer(0.1, fn () => null);
        $delay = $manager->getNextTimerDelay();

        expect($delay)->toBeFloat();
        expect($delay)->toBeGreaterThan(0);
        expect($delay)->toBeLessThanOrEqual(0.1);
    });

    it('can clear all timers', function () {
        $loop = \uv_loop_new();
        $manager = new TimerManager($loop);

        $manager->addTimer(0.1, fn () => null);
        $manager->addPeriodicTimer(0.1, fn () => null);

        expect($manager->hasTimers())->toBeTrue();

        $manager->clearAllTimers();

        expect($manager->hasTimers())->toBeFalse();
    });

    it('returns false from hasReadyTimers', function () {
        $loop = \uv_loop_new();
        $manager = new TimerManager($loop);

        $manager->addTimer(0.001, fn () => null);

        expect($manager->hasReadyTimers())->toBeFalse();
    });

    it('reschedules master timer after consuming ready timers', function () {
        $loop = \uv_loop_new();
        $manager = new TimerManager($loop);
        $executed = 0;

        $manager->addTimer(0.001, function () use (&$executed) {
            $executed++;
        });

        $manager->addTimer(0.1, function () use (&$executed) {
            $executed++;
        });

        \uv_run($loop, UV::RUN_ONCE);

        $callbacks = $manager->collectReadyTimers();
        foreach ($callbacks as $callback) {
            $callback();
        }

        $manager->rescheduleMaster();

        expect($manager->hasTimers())->toBeTrue();
        expect($executed)->toBe(1);
    });
})->skip(fn () => ! extension_loaded('uv'), 'uv extension required');
