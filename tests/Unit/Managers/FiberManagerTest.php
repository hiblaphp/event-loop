<?php

declare(strict_types=1);

use Hibla\EventLoop\Managers\FiberManager;

describe('FiberManager', function () {
    it('starts with no fibers', function () {
        $manager = new FiberManager();
        expect($manager->hasFibers())->toBeFalse();
        expect($manager->hasActiveFibers())->toBeFalse();
    });

    it('can add and process fibers', function () {
        $manager = new FiberManager();
        $executed = false;

        $fiber = new Fiber(function () use (&$executed) {
            $executed = true;

            return 'done';
        });

        $manager->addFiber($fiber);
        expect($manager->hasFibers())->toBeTrue();

        $processed = $manager->processFibers();
        expect($processed)->toBeTrue();
        expect($executed)->toBeTrue();
    });

    it('handles suspended fibers - fibers remain suspended until explicitly scheduled', function () {
        $manager = new FiberManager();
        $step = 0;

        $fiber = new Fiber(function () use (&$step) {
            $step = 1;
            Fiber::suspend('suspended');
            $step = 2;

            return 'completed';
        });

        $manager->addFiber($fiber);

        $processed = $manager->processFibers();
        expect($processed)->toBeTrue();
        expect($step)->toBe(1);
        expect($fiber->isSuspended())->toBeTrue();

        $processed = $manager->processFibers();
        expect($processed)->toBeFalse();
        expect($step)->toBe(1);
        expect($fiber->isSuspended())->toBeTrue();

        $manager->scheduleFiber($fiber);
        expect($manager->hasActiveFibers())->toBeTrue();

        $processed = $manager->processFibers();
        expect($processed)->toBeTrue();
        expect($step)->toBe(2);
        expect($fiber->isTerminated())->toBeTrue();
    });

    it('can schedule a terminated fiber (no-op)', function () {
        $manager = new FiberManager();
        $executed = false;

        $fiber = new Fiber(function () use (&$executed) {
            $executed = true;

            return 'scheduled';
        });

        $fiber->start();
        expect($fiber->isTerminated())->toBeTrue();
        expect($executed)->toBeTrue();

        $manager->scheduleFiber($fiber);
        expect($manager->hasFibers())->toBeFalse();
        expect($manager->hasActiveFibers())->toBeFalse();
    });

    it('can schedule a suspended fiber for resumption', function () {
        $manager = new FiberManager();
        $step = 0;

        $fiber = new Fiber(function () use (&$step) {
            $step = 1;
            Fiber::suspend();
            $step = 2;
        });

        $fiber->start();
        expect($fiber->isSuspended())->toBeTrue();
        expect($step)->toBe(1);

        $manager->scheduleFiber($fiber);

        expect($manager->hasActiveFibers())->toBeFalse();

        $manager2 = new FiberManager();
        $fiber2 = new Fiber(function () use (&$step) {
            $step = 10;
            Fiber::suspend();
            $step = 20;
        });

        $manager2->addFiber($fiber2);
        $manager2->processFibers();

        expect($step)->toBe(10);
        expect($fiber2->isSuspended())->toBeTrue();

        $manager2->scheduleFiber($fiber2);
        expect($manager2->hasActiveFibers())->toBeTrue();

        $processed = $manager2->processFibers();
        expect($processed)->toBeTrue();
        expect($step)->toBe(20);
        expect($fiber2->isTerminated())->toBeTrue();
    });

    it('tracks fibers correctly - scheduleFiber does not increment active count', function () {
        $manager = new FiberManager();

        $fiber = new Fiber(function () {
            Fiber::suspend();
        });

        $manager->addFiber($fiber);
        expect($manager->hasFibers())->toBeTrue();

        $manager->processFibers();
        expect($fiber->isSuspended())->toBeTrue();
        expect($manager->hasFibers())->toBeTrue();
        expect($manager->hasActiveFibers())->toBeTrue();

        $manager->scheduleFiber($fiber);
        expect($manager->hasFibers())->toBeTrue();
        expect($manager->hasActiveFibers())->toBeTrue();
    });

    it('can prepare for shutdown', function () {
        $manager = new FiberManager();

        expect($manager->isAcceptingNewFibers())->toBeTrue();

        $manager->prepareForShutdown();

        expect($manager->isAcceptingNewFibers())->toBeFalse();

        $fiber = new Fiber(fn () => 'test');
        $manager->addFiber($fiber);

        expect($manager->hasFibers())->toBeFalse();
    });

    it('can clear all fibers', function () {
        $manager = new FiberManager();

        $fiber1 = new Fiber(fn () => 'test1');
        $fiber2 = new Fiber(fn () => 'test2');

        $manager->addFiber($fiber1);
        $manager->addFiber($fiber2);

        expect($manager->hasFibers())->toBeTrue();

        $manager->clearFibers();

        expect($manager->hasFibers())->toBeFalse();
    });

    it('processes ready fibers in queue order', function () {
        $manager = new FiberManager();
        $order = [];

        $suspendedFiber = new Fiber(function () use (&$order) {
            $order[] = 'suspended_start';
            Fiber::suspend();
            $order[] = 'suspended_resume';
        });
        $manager->addFiber($suspendedFiber);
        $manager->processFibers();

        $newFiber = new Fiber(function () use (&$order) {
            $order[] = 'new_fiber';
        });
        $manager->addFiber($newFiber);

        $manager->processFibers();

        expect($order)->toBe(['suspended_start', 'new_fiber']);

        $manager->scheduleFiber($suspendedFiber);
        $manager->processFibers();

        expect($order)->toBe(['suspended_start', 'new_fiber', 'suspended_resume']);
    });

    it('handles scheduling during fiber execution', function () {
        $manager = new FiberManager();
        $order = [];

        $fiber1 = new Fiber(function () use (&$order, $manager) {
            $order[] = 'fiber1_start';

            $fiber2 = new Fiber(function () use (&$order) {
                $order[] = 'fiber2_executed';
            });
            $fiber2->start();
            $manager->scheduleFiber($fiber2);

            $order[] = 'fiber1_end';
        });

        $manager->addFiber($fiber1);
        $manager->processFibers();

        expect($order)->toBe(['fiber1_start', 'fiber2_executed', 'fiber1_end']);

        $processed = $manager->processFibers();
        expect($processed)->toBeFalse();

        expect($manager->hasFibers())->toBeFalse();
    });

    it('only resumes fibers when explicitly scheduled', function () {
        $manager = new FiberManager();
        $resumeCount = 0;

        $fiber = new Fiber(function () use (&$resumeCount): never {
            while (true) {
                $resumeCount++;
                Fiber::suspend();
            }
        });

        $manager->addFiber($fiber);

        $manager->processFibers();
        expect($resumeCount)->toBe(1);

        $manager->processFibers();
        $manager->processFibers();
        $manager->processFibers();
        expect($resumeCount)->toBe(1);

        $manager->scheduleFiber($fiber);
        $manager->processFibers();
        expect($resumeCount)->toBe(2);

        $manager->processFibers();
        $manager->processFibers();
        expect($resumeCount)->toBe(2);

        $manager->scheduleFiber($fiber);
        $manager->processFibers();
        expect($resumeCount)->toBe(3);
    });
});
