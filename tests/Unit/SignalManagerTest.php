<?php

declare(strict_types=1);

use Hibla\EventLoop\Managers\SignalManager;

describe('SignalManager', function () {
    it('adds and processes signal listeners', function () {
        $signalManager = new SignalManager();
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;
        };

        expect($callCount)->toBe(0);

        $signalId = $signalManager->addSignal(SIGUSR1, $callback);

        expect($callCount)->toBe(0)
            ->and($signalId)->toBeString()
            ->and($signalManager->hasSignals())->toBeTrue()
            ->and($signalManager->getListenerCount(SIGUSR1))->toBe(1);
    })->skip(fn () => !function_exists('pcntl_signal'), 'pcntl extension required');

    it('adds multiple listeners for the same signal', function () {
        $signalManager = new SignalManager();
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;
        };

        $signalId1 = $signalManager->addSignal(SIGUSR1, $callback);
        $signalId2 = $signalManager->addSignal(SIGUSR1, $callback);
        $signalId3 = $signalManager->addSignal(SIGUSR1, $callback);

        expect($signalId1)->not->toBe($signalId2)
            ->and($signalId2)->not->toBe($signalId3)
            ->and($signalManager->getListenerCount(SIGUSR1))->toBe(3)
            ->and($callCount)->toBe(0);
    })->skip(fn () => !function_exists('pcntl_signal'), 'pcntl extension required');

    it('removes signal listeners by id', function () {
        $signalManager = new SignalManager();
        $callback = function () {};

        $signalId1 = $signalManager->addSignal(SIGUSR1, $callback);
        $signalId2 = $signalManager->addSignal(SIGUSR1, $callback);

        expect($signalManager->getListenerCount(SIGUSR1))->toBe(2);

        $removed = $signalManager->removeSignal($signalId1);

        expect($removed)->toBeTrue()
            ->and($signalManager->getListenerCount(SIGUSR1))->toBe(1);

        $removed = $signalManager->removeSignal($signalId2);

        expect($removed)->toBeTrue()
            ->and($signalManager->getListenerCount(SIGUSR1))->toBe(0)
            ->and($signalManager->hasSignals())->toBeFalse();
    })->skip(fn () => !function_exists('pcntl_signal'), 'pcntl extension required');

    it('returns false when removing non-existent signal', function () {
        $signalManager = new SignalManager();

        $removed = $signalManager->removeSignal('non_existent_id');

        expect($removed)->toBeFalse();
    })->skip(fn () => !function_exists('pcntl_signal'), 'pcntl extension required');

    it('handles multiple signals independently', function () {
        $signalManager = new SignalManager();
        $callCount1 = 0;
        $callCount2 = 0;

        $callback1 = function () use (&$callCount1) {
            $callCount1++;
        };

        $callback2 = function () use (&$callCount2) {
            $callCount2++;
        };

        $signalManager->addSignal(SIGUSR1, $callback1);
        $signalManager->addSignal(SIGUSR2, $callback2);

        expect($signalManager->getListenerCount(SIGUSR1))->toBe(1)
            ->and($signalManager->getListenerCount(SIGUSR2))->toBe(1)
            ->and($callCount1)->toBe(0)
            ->and($callCount2)->toBe(0);
    })->skip(fn () => !function_exists('pcntl_signal'), 'pcntl extension required');

    it('clears all signal listeners', function () {
        $signalManager = new SignalManager();
        $callback = function () {};

        $signalManager->addSignal(SIGUSR1, $callback);
        $signalManager->addSignal(SIGUSR2, $callback);
        $signalManager->addSignal(SIGUSR1, $callback);

        expect($signalManager->hasSignals())->toBeTrue()
            ->and($signalManager->getListenerCount(SIGUSR1))->toBe(2)
            ->and($signalManager->getListenerCount(SIGUSR2))->toBe(1);

        $signalManager->clearAllSignals();

        expect($signalManager->hasSignals())->toBeFalse()
            ->and($signalManager->getListenerCount(SIGUSR1))->toBe(0)
            ->and($signalManager->getListenerCount(SIGUSR2))->toBe(0);
    })->skip(fn () => !function_exists('pcntl_signal'), 'pcntl extension required');

    it('processes signals when hasSignals returns true', function () {
        $signalManager = new SignalManager();
        $callback = function () {};

        expect($signalManager->processSignals())->toBeFalse();

        $signalManager->addSignal(SIGUSR1, $callback);

        expect($signalManager->processSignals())->toBeTrue();
    })->skip(fn () => !function_exists('pcntl_signal'), 'pcntl extension required');

    it('returns correct listener count for unregistered signal', function () {
        $signalManager = new SignalManager();

        expect($signalManager->getListenerCount(SIGUSR1))->toBe(0)
            ->and($signalManager->getListenerCount(SIGUSR2))->toBe(0);
    })->skip(fn () => !function_exists('pcntl_signal'), 'pcntl extension required');

    it('generates unique ids for each listener', function () {
        $signalManager = new SignalManager();
        $callback = function () {};

        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = $signalManager->addSignal(SIGUSR1, $callback);
        }

        $uniqueIds = array_unique($ids);

        expect(count($uniqueIds))->toBe(10)
            ->and($signalManager->getListenerCount(SIGUSR1))->toBe(10);
    })->skip(fn () => !function_exists('pcntl_signal'), 'pcntl extension required');

    it('maintains signal registration after removing some listeners', function () {
        $signalManager = new SignalManager();
        $callback = function () {};

        $id1 = $signalManager->addSignal(SIGUSR1, $callback);
        $id2 = $signalManager->addSignal(SIGUSR1, $callback);
        $id3 = $signalManager->addSignal(SIGUSR1, $callback);

        expect($signalManager->getListenerCount(SIGUSR1))->toBe(3);

        $signalManager->removeSignal($id2);

        expect($signalManager->getListenerCount(SIGUSR1))->toBe(2)
            ->and($signalManager->hasSignals())->toBeTrue();

        $signalManager->removeSignal($id1);
        $signalManager->removeSignal($id3);

        expect($signalManager->getListenerCount(SIGUSR1))->toBe(0)
            ->and($signalManager->hasSignals())->toBeFalse();
    })->skip(fn () => !function_exists('pcntl_signal'), 'pcntl extension required');
});