<?php

declare(strict_types=1);

use Hibla\EventLoop\Handlers\ActivityHandler;

describe('ActivityHandler', function () {
    it('initializes with current timestamp', function () {
        $handler = new ActivityHandler();
        $lastActivity = $handler->getLastActivity();

        expect($lastActivity)->toBeValidTimestamp();
        expect($lastActivity)->toBeLessThanOrEqual(microtime(true));
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
        expect($stats['avg_interval'])->toBeGreaterThan(0);
    });

    it('detects idle state correctly', function () {
        $handler = new ActivityHandler();

        expect($handler->isIdle())->toBeFalse();

        $handler->updateLastActivity();
        expect($handler->isIdle())->toBeFalse();
    });

    it('provides comprehensive activity stats', function () {
        $handler = new ActivityHandler();
        $handler->updateLastActivity();

        $stats = $handler->getActivityStats();

        expect($stats)->toHaveKeys(['counter', 'avg_interval', 'idle_time']);
        expect($stats['counter'])->toBeInt();
        expect($stats['avg_interval'])->toBeFloat();
        expect($stats['idle_time'])->toBeFloat();
    });
});
