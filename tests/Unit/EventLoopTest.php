<?php

declare(strict_types=1);

use Hibla\EventLoop\EventLoop;

describe('EventLoop Singleton', function () {
    it('returns the same instance', function () {
        $loop1 = EventLoop::getInstance();
        $loop2 = EventLoop::getInstance();

        expect($loop1)->toBe($loop2);
    });

    it('can be reset for testing', function () {
        $loop1 = EventLoop::getInstance();
        EventLoop::reset();
        $loop2 = EventLoop::getInstance();

        expect($loop1)->not->toBe($loop2);
    });
});

describe('EventLoop State Management', function () {
    it('starts in running state', function () {
        $loop = EventLoop::getInstance();
        expect($loop->isRunning())->toBeTrue();
    });

    it('can be stopped', function () {
        $loop = EventLoop::getInstance();
        $loop->stop();
        expect($loop->isRunning())->toBeFalse();
    });

    it('can be force stopped', function () {
        $loop = EventLoop::getInstance();
        $loop->forceStop();
        expect($loop->isRunning())->toBeFalse();
    });

    it('reports idle state correctly', function () {
        $loop = EventLoop::getInstance();
        expect($loop->isIdle())->toBeTrue();

        $loop->addTimer(0.1, fn () => null);
        expect($loop->isIdle())->toBeFalse();
    });
});

describe('EventLoop Managers Access', function () {
    it('provides access to timer manager', function () {
        $loop = EventLoop::getInstance();
        $timerManager = $loop->getTimerManager();

        expect($timerManager)->toBeInstanceOf(Hibla\EventLoop\Managers\TimerManager::class);
    });

    it('provides access to socket manager', function () {
        $loop = EventLoop::getInstance();
        $socketManager = $loop->getSocketManager();

        expect($socketManager)->toBeInstanceOf(Hibla\EventLoop\Managers\SocketManager::class);
    });
});
