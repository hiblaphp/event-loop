<?php

declare(strict_types=1);

use Hibla\EventLoop\Handlers\TickHandler;

describe('TickHandler', function () {
    it('starts with no callbacks', function () {
        $handler = new TickHandler();

        expect($handler->hasTickCallbacks())->toBeFalse();
        expect($handler->hasDeferredCallbacks())->toBeFalse();
    });

    it('can add next tick callbacks', function () {
        $handler = new TickHandler();
        $executed = false;

        $handler->addNextTick(function () use (&$executed) {
            $executed = true;
        });

        expect($handler->hasTickCallbacks())->toBeTrue();

        $processed = $handler->processNextTickCallbacks();
        expect($processed)->toBeTrue();
        expect($executed)->toBeTrue();
        expect($handler->hasTickCallbacks())->toBeFalse();
    });

    it('can add deferred callbacks', function () {
        $handler = new TickHandler();
        $executed = false;

        $handler->addDeferred(function () use (&$executed) {
            $executed = true;
        });

        expect($handler->hasDeferredCallbacks())->toBeTrue();

        $processed = $handler->processDeferredCallbacks();
        expect($processed)->toBeTrue();
        expect($executed)->toBeTrue();
        expect($handler->hasDeferredCallbacks())->toBeFalse();
    });

    it('processes callbacks in batches', function () {
        $handler = new TickHandler();
        $executed = 0;

        // Add more than batch size (100) callbacks
        for ($i = 0; $i < 150; $i++) {
            $handler->addNextTick(function () use (&$executed) {
                $executed++;
            });
        }

        // First batch should process 100
        $processed = $handler->processNextTickCallbacks();
        expect($processed)->toBeTrue();
        expect($executed)->toBe(100);
        expect($handler->hasTickCallbacks())->toBeTrue();

        // Second batch should process remaining 50
        $processed = $handler->processNextTickCallbacks();
        expect($processed)->toBeTrue();
        expect($executed)->toBe(150);
        expect($handler->hasTickCallbacks())->toBeFalse();
    });

    it('handles callback exceptions gracefully', function () {
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

    it('can clear all callbacks', function () {
        $handler = new TickHandler();

        $handler->addNextTick(fn () => null);
        $handler->addDeferred(fn () => null);

        expect($handler->hasTickCallbacks())->toBeTrue();
        expect($handler->hasDeferredCallbacks())->toBeTrue();

        $handler->clearAllCallbacks();

        expect($handler->hasTickCallbacks())->toBeFalse();
        expect($handler->hasDeferredCallbacks())->toBeFalse();
    });
});
