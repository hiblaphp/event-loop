<?php

declare(strict_types=1);

use Hibla\EventLoop\Handlers\TickHandler;

describe('TickHandler', function () {
    it('starts with no callbacks', function () {
        $handler = new TickHandler();

        expect($handler->hasTickCallbacks())->toBeFalse();
        expect($handler->hasMicrotaskCallbacks())->toBeFalse();
        expect($handler->hasDeferredCallbacks())->toBeFalse();
        expect($handler->hasWork())->toBeFalse();
    });

    it('can add next tick callbacks', function () {
        $handler = new TickHandler();
        $executed = false;

        $handler->addNextTick(function () use (&$executed) {
            $executed = true;
        });

        expect($handler->hasTickCallbacks())->toBeTrue();
        expect($handler->hasWork())->toBeTrue();

        $processed = $handler->processNextTickCallbacks();
        expect($processed)->toBeTrue();
        expect($executed)->toBeTrue();
        expect($handler->hasTickCallbacks())->toBeFalse();
    });

    it('can add microtask callbacks', function () {
        $handler = new TickHandler();
        $executed = false;

        $handler->addMicrotask(function () use (&$executed) {
            $executed = true;
        });

        expect($handler->hasMicrotaskCallbacks())->toBeTrue();
        expect($handler->hasWork())->toBeTrue();

        $processed = $handler->processMicrotasks();
        expect($processed)->toBeTrue();
        expect($executed)->toBeTrue();
        expect($handler->hasMicrotaskCallbacks())->toBeFalse();
    });

    it('can add deferred callbacks', function () {
        $handler = new TickHandler();
        $executed = false;

        $handler->addDeferred(function () use (&$executed) {
            $executed = true;
        });

        expect($handler->hasDeferredCallbacks())->toBeTrue();
        expect($handler->hasWork())->toBeTrue();

        $processed = $handler->processDeferredCallbacks();
        expect($processed)->toBeTrue();
        expect($executed)->toBeTrue();
        expect($handler->hasDeferredCallbacks())->toBeFalse();
    });

    it('processes multiple callbacks of same type in FIFO order', function () {
        $handler = new TickHandler();
        $order = [];

        $handler->addNextTick(function () use (&$order) {
            $order[] = 'tick1';
        });
        $handler->addNextTick(function () use (&$order) {
            $order[] = 'tick2';
        });
        $handler->addNextTick(function () use (&$order) {
            $order[] = 'tick3';
        });

        $handler->processNextTickCallbacks();

        expect($order)->toBe(['tick1', 'tick2', 'tick3']);
    });

    it('handles nextTick callback exceptions gracefully', function () {
        $handler = new TickHandler();
        $executed = false;

        $handler->addNextTick(function () {
            throw new Exception('Test exception');
        });

        $handler->addNextTick(function () use (&$executed) {
            $executed = true;
        });

        $processed = $handler->processNextTickCallbacks();
        expect($processed)->toBeTrue();
        expect($executed)->toBeTrue();
    });

    it('handles microtask callback exceptions gracefully', function () {
        $handler = new TickHandler();
        $executed = false;

        $handler->addMicrotask(function () {
            throw new Exception('Microtask exception');
        });

        $handler->addMicrotask(function () use (&$executed) {
            $executed = true;
        });

        $processed = $handler->processMicrotasks();
        expect($processed)->toBeTrue();
        expect($executed)->toBeTrue();
    });

    it('handles deferred callback exceptions gracefully', function () {
        $handler = new TickHandler();
        $executed = false;

        $handler->addDeferred(function () {
            throw new Exception('Deferred exception');
        });

        $handler->addDeferred(function () use (&$executed) {
            $executed = true;
        });

        $processed = $handler->processDeferredCallbacks();
        expect($processed)->toBeTrue();
        expect($executed)->toBeTrue();
    });

    it('returns false when processing empty queues', function () {
        $handler = new TickHandler();

        expect($handler->processNextTickCallbacks())->toBeFalse();
        expect($handler->processMicrotasks())->toBeFalse();
        expect($handler->processDeferredCallbacks())->toBeFalse();
    });

    it('can clear all callback types', function () {
        $handler = new TickHandler();

        $handler->addNextTick(fn () => null);
        $handler->addMicrotask(fn () => null);
        $handler->addDeferred(fn () => null);

        expect($handler->hasTickCallbacks())->toBeTrue();
        expect($handler->hasMicrotaskCallbacks())->toBeTrue();
        expect($handler->hasDeferredCallbacks())->toBeTrue();
        expect($handler->hasWork())->toBeTrue();

        $handler->clearAllCallbacks();

        expect($handler->hasTickCallbacks())->toBeFalse();
        expect($handler->hasMicrotaskCallbacks())->toBeFalse();
        expect($handler->hasDeferredCallbacks())->toBeFalse();
        expect($handler->hasWork())->toBeFalse();
    });

    it('provides accurate statistics', function () {
        $handler = new TickHandler();

        $handler->addNextTick(fn () => null);
        $handler->addNextTick(fn () => null);
        $handler->addMicrotask(fn () => null);
        $handler->addMicrotask(fn () => null);
        $handler->addMicrotask(fn () => null);
        $handler->addDeferred(fn () => null);

        $stats = $handler->getStats();

        expect($stats)->toBe([
            'tick_callbacks' => 2,
            'microtask_callbacks' => 3,
            'deferred_callbacks' => 1,
            'total_callbacks' => 6,
        ]);
    });

    it('updates statistics after processing', function () {
        $handler = new TickHandler();

        $handler->addNextTick(fn () => null);
        $handler->addMicrotask(fn () => null);
        $handler->addDeferred(fn () => null);

        expect($handler->getStats()['total_callbacks'])->toBe(3);

        $handler->processNextTickCallbacks();
        expect($handler->getStats()['total_callbacks'])->toBe(2);

        $handler->processMicrotasks();
        expect($handler->getStats()['total_callbacks'])->toBe(1);

        $handler->processDeferredCallbacks();
        expect($handler->getStats()['total_callbacks'])->toBe(0);
    });

    it('handles recursive microtask scheduling with draining behavior', function () {
        $handler = new TickHandler();
        $count = 0;

        $addRecursive = function () use (&$count, &$handler, &$addRecursive) {
            $count++;
            if ($count < 3) {
                $handler->addMicrotask($addRecursive);
            }
        };

        $handler->addMicrotask($addRecursive);

        // With draining behavior, all microtasks are processed in one call
        $handler->processMicrotasks();

        // All 3 microtasks should have executed (draining behavior)
        expect($count)->toBe(3);
        expect($handler->hasMicrotaskCallbacks())->toBeFalse();
    });

    it('isolates different callback types', function () {
        $handler = new TickHandler();
        $tickExecuted = false;
        $microtaskExecuted = false;
        $deferredExecuted = false;

        $handler->addNextTick(function () use (&$tickExecuted) {
            $tickExecuted = true;
        });
        $handler->addMicrotask(function () use (&$microtaskExecuted) {
            $microtaskExecuted = true;
        });
        $handler->addDeferred(function () use (&$deferredExecuted) {
            $deferredExecuted = true;
        });

        $handler->processNextTickCallbacks();
        expect($tickExecuted)->toBeTrue();
        expect($microtaskExecuted)->toBeFalse();
        expect($deferredExecuted)->toBeFalse();

        $handler->processMicrotasks();
        expect($microtaskExecuted)->toBeTrue();
        expect($deferredExecuted)->toBeFalse();

        $handler->processDeferredCallbacks();
        expect($deferredExecuted)->toBeTrue();
    });

    it('handles high volume of callbacks efficiently', function () {
        $handler = new TickHandler();
        $count = 0;
        $total = 10000;

        for ($i = 0; $i < $total; $i++) {
            $handler->addMicrotask(function () use (&$count) {
                $count++;
            });
        }

        expect($handler->hasMicrotaskCallbacks())->toBeTrue();
        expect($handler->getStats()['microtask_callbacks'])->toBe($total);

        $handler->processMicrotasks();

        expect($count)->toBe($total);
        expect($handler->hasMicrotaskCallbacks())->toBeFalse();
    });

    it('prevents infinite microtask loops with safety limit', function () {
        $handler = new TickHandler();
        $count = 0;

        $infiniteRecursive = function () use (&$count, &$handler, &$infiniteRecursive) {
            $count++;
            $handler->addMicrotask($infiniteRecursive);
        };

        $handler->addMicrotask($infiniteRecursive);

        $handler->processMicrotasks(100);

        expect($count)->toBe(100);
        expect($handler->hasMicrotaskCallbacks())->toBeTrue();
    });
});
