<?php

declare(strict_types=1);

use Hibla\EventLoop\Handlers\ActivityHandler;

describe('ActivityHandler', function () {
    it('initializes with current timestamp', function () {
        $handler = new ActivityHandler();
        $lastActivity = $handler->getLastActivity();

        expect($lastActivity)->toBeInt();
        expect($lastActivity)->toBeGreaterThan(0);
    });

    it('updates activity timestamp', function () {
        $handler = new ActivityHandler();
        $initialTime = $handler->getLastActivity();

        usleep(1000);
        $handler->updateLastActivity();

        expect($handler->getLastActivity())->toBeGreaterThan($initialTime);
    });

    it('tracks activity counter', function () {
        $handler = new ActivityHandler();
        $initialStats = $handler->getActivityStats();

        expect($initialStats['counter'])->toBe(0);

        $handler->updateLastActivity();
        $handler->updateLastActivity();

        $stats = $handler->getActivityStats();
        expect($stats['counter'])->toBe(2);
    });

    it('calculates average activity interval', function () {
        $handler = new ActivityHandler();

        $handler->updateLastActivity();
        usleep(10000);
        $handler->updateLastActivity();
        usleep(20000);
        $handler->updateLastActivity();

        $stats = $handler->getActivityStats();
        expect($stats['avg_interval_ns'])->toBeGreaterThan(0);
    });

    it('detects idle state correctly', function () {
        $handler = new ActivityHandler();

        expect($handler->isIdle())->toBeFalse();

        $handler->updateLastActivity();
        expect($handler->isIdle())->toBeFalse();
    });

    it('detects idle state after threshold', function () {
        $handler = new ActivityHandler();
        $handler->updateLastActivity();

        // Sleep longer than the idle threshold (5 seconds)
        sleep(6);

        expect($handler->isIdle())->toBeTrue();
    });

    it('provides comprehensive activity stats', function () {
        $handler = new ActivityHandler();
        $handler->updateLastActivity();

        $stats = $handler->getActivityStats();

        expect($stats)->toHaveKeys(['counter', 'avg_interval_ns', 'idle_time_ns']);
        expect($stats['counter'])->toBeInt();
        expect($stats['avg_interval_ns'])->toBeInt();
        expect($stats['idle_time_ns'])->toBeInt();
    });

    it('calculates adaptive threshold based on activity', function () {
        $handler = new ActivityHandler();

        // Perform 101 updates to exceed the 100 count threshold
        for ($i = 0; $i < 101; $i++) {
            $handler->updateLastActivity();
            usleep(1000);
        }

        $stats = $handler->getActivityStats();
        expect($stats['counter'])->toBe(101);

        // Average interval should be around 1ms = 1,000,000 ns
        expect($stats['avg_interval_ns'])->toBeGreaterThan(800_000);
    });
});
