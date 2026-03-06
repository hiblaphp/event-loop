<?php

declare(strict_types=1);

use Hibla\EventLoop\Drivers\Uv\Managers\SignalManager;

describe('SignalManager (UV)', function () {
    it('starts with no signals', function () {
        $loop = \uv_loop_new();
        $manager = new SignalManager($loop);

        expect($manager->hasSignals())->toBeFalse();
    });

    it('can add a signal listener and returns a string ID', function () {
        $loop = \uv_loop_new();
        $manager = new SignalManager($loop);

        $signalId = $manager->addSignal(SIGUSR1, fn () => null);

        expect($signalId)->toBeString()->not->toBeEmpty();
        expect($manager->hasSignals())->toBeTrue();
        expect($manager->getListenerCount(SIGUSR1))->toBe(1);

        $manager->clearAllSignals();
    });

    it('adds multiple listeners for the same signal', function () {
        $loop = \uv_loop_new();
        $manager = new SignalManager($loop);

        $id1 = $manager->addSignal(SIGUSR1, fn () => null);
        $id2 = $manager->addSignal(SIGUSR1, fn () => null);
        $id3 = $manager->addSignal(SIGUSR1, fn () => null);

        expect($id1)->not->toBe($id2)
            ->and($id2)->not->toBe($id3)
            ->and($manager->getListenerCount(SIGUSR1))->toBe(3)
        ;

        $manager->clearAllSignals();
    });

    it('removes signal listeners by id', function () {
        $loop = \uv_loop_new();
        $manager = new SignalManager($loop);

        $id1 = $manager->addSignal(SIGUSR1, fn () => null);
        $id2 = $manager->addSignal(SIGUSR1, fn () => null);

        expect($manager->getListenerCount(SIGUSR1))->toBe(2);

        expect($manager->removeSignal($id1))->toBeTrue();
        expect($manager->getListenerCount(SIGUSR1))->toBe(1);

        expect($manager->removeSignal($id2))->toBeTrue();
        expect($manager->getListenerCount(SIGUSR1))->toBe(0);
        expect($manager->hasSignals())->toBeFalse();
    });

    it('returns false when removing non-existent signal', function () {
        $loop = \uv_loop_new();
        $manager = new SignalManager($loop);

        expect($manager->removeSignal('non_existent_id'))->toBeFalse();
    });

    it('handles multiple signals independently', function () {
        $loop = \uv_loop_new();
        $manager = new SignalManager($loop);

        $id1 = $manager->addSignal(SIGUSR1, fn () => null);
        $id2 = $manager->addSignal(SIGUSR2, fn () => null);

        expect($manager->getListenerCount(SIGUSR1))->toBe(1)
            ->and($manager->getListenerCount(SIGUSR2))->toBe(1)
        ;

        $manager->clearAllSignals();
    });

    it('clears all signal listeners', function () {
        $loop = \uv_loop_new();
        $manager = new SignalManager($loop);

        $manager->addSignal(SIGUSR1, fn () => null);
        $manager->addSignal(SIGUSR2, fn () => null);
        $manager->addSignal(SIGUSR1, fn () => null);

        expect($manager->hasSignals())->toBeTrue()
            ->and($manager->getListenerCount(SIGUSR1))->toBe(2)
            ->and($manager->getListenerCount(SIGUSR2))->toBe(1)
        ;

        $manager->clearAllSignals();

        expect($manager->hasSignals())->toBeFalse()
            ->and($manager->getListenerCount(SIGUSR1))->toBe(0)
            ->and($manager->getListenerCount(SIGUSR2))->toBe(0)
        ;
    });

    it('returns false from processSignals as it is a no-op in the UV driver', function () {
        $loop = \uv_loop_new();
        $manager = new SignalManager($loop);

        $manager->addSignal(SIGUSR1, fn () => null);

        expect($manager->processSignals())->toBeFalse();

        $manager->clearAllSignals();
    });

    it('returns correct listener count for unregistered signal', function () {
        $loop = \uv_loop_new();
        $manager = new SignalManager($loop);

        expect($manager->getListenerCount(SIGUSR1))->toBe(0)
            ->and($manager->getListenerCount(SIGUSR2))->toBe(0)
        ;
    });

    it('generates unique ids for each listener', function () {
        $loop = \uv_loop_new();
        $manager = new SignalManager($loop);

        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = $manager->addSignal(SIGUSR1, fn () => null);
        }

        expect(count(array_unique($ids)))->toBe(10)
            ->and($manager->getListenerCount(SIGUSR1))->toBe(10)
        ;

        $manager->clearAllSignals();
    });

    it('maintains signal registration after removing some listeners', function () {
        $loop = \uv_loop_new();
        $manager = new SignalManager($loop);

        $id1 = $manager->addSignal(SIGUSR1, fn () => null);
        $id2 = $manager->addSignal(SIGUSR1, fn () => null);
        $id3 = $manager->addSignal(SIGUSR1, fn () => null);

        expect($manager->getListenerCount(SIGUSR1))->toBe(3);

        $manager->removeSignal($id2);

        expect($manager->getListenerCount(SIGUSR1))->toBe(2)
            ->and($manager->hasSignals())->toBeTrue()
        ;

        $manager->removeSignal($id1);
        $manager->removeSignal($id3);

        expect($manager->getListenerCount(SIGUSR1))->toBe(0)
            ->and($manager->hasSignals())->toBeFalse()
        ;
    });
})->skip(fn () => ! extension_loaded('uv') || ! function_exists('pcntl_signal'), 'uv and pcntl extensions required');