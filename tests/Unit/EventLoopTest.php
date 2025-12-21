<?php

declare(strict_types=1);

use Hibla\EventLoop\EventLoopFactory;

describe('EventLoop Singleton', function () {
    it('returns the same instance', function () {
        $loop1 = EventLoopFactory::getInstance();
        $loop2 = EventLoopFactory::getInstance();

        expect($loop1)->toBe($loop2);
    });

    it('can be reset for testing', function () {
        $loop1 = EventLoopFactory::getInstance();
        EventLoopFactory::reset();
        $loop2 = EventLoopFactory::getInstance();

        expect($loop1)->not->toBe($loop2);
    });
});

describe('EventLoop State Management', function () {
    it('starts in running state', function () {
        $loop = EventLoopFactory::getInstance();
        expect($loop->isRunning())->toBeTrue();
    });

    it('can be stopped', function () {
        $loop = EventLoopFactory::getInstance();
        $loop->stop();
        expect($loop->isRunning())->toBeFalse();
    });

    it('can be force stopped', function () {
        $loop = EventLoopFactory::getInstance();
        $loop->forceStop();
        expect($loop->isRunning())->toBeFalse();
    });

    it('reports idle state correctly', function () {
        $loop = EventLoopFactory::getInstance();
        expect($loop->isIdle())->toBeTrue();

        $loop->addTimer(0.1, fn () => null);
        expect($loop->isIdle())->toBeFalse();
    });
});
