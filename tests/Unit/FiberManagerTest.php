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

    it('handles suspended fibers', function () {
        $manager = new FiberManager();
        $step = 0;

        $fiber = new Fiber(function () use (&$step) {
            $step = 1;
            Fiber::suspend('suspended');
            $step = 2;

            return 'completed';
        });

        $manager->addFiber($fiber);

        // First process - should start and suspend
        $processed = $manager->processFibers();
        expect($processed)->toBeTrue();
        expect($step)->toBe(1);
        expect($fiber->isSuspended())->toBeTrue();

        // Second process - should resume
        $processed = $manager->processFibers();
        expect($processed)->toBeTrue();
        expect($step)->toBe(2);
        expect($fiber->isTerminated())->toBeTrue();
    });

    it('can prepare for shutdown', function () {
        $manager = new FiberManager();

        expect($manager->isAcceptingNewFibers())->toBeTrue();

        $manager->prepareForShutdown();

        expect($manager->isAcceptingNewFibers())->toBeFalse();

        // Should not accept new fibers after shutdown preparation
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

    it('processes new fibers before suspended ones', function () {
        $manager = new FiberManager();
        $order = [];

        // Add a suspended fiber first
        $suspendedFiber = new Fiber(function () use (&$order) {
            $order[] = 'suspended_start';
            Fiber::suspend();
            $order[] = 'suspended_resume';
        });
        $manager->addFiber($suspendedFiber);
        $manager->processFibers(); // This will start and suspend it

        // Add a new fiber
        $newFiber = new Fiber(function () use (&$order) {
            $order[] = 'new_fiber';
        });
        $manager->addFiber($newFiber);

        // Process should handle new fiber first
        $manager->processFibers();

        expect($order)->toContain('new_fiber');
        expect(array_search('new_fiber', $order))->toBeLessThan(array_search('suspended_resume', $order) ?: PHP_INT_MAX);
    });
});
