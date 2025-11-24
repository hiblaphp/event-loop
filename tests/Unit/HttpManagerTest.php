<?php

declare(strict_types=1);

use Hibla\EventLoop\Managers\HttpRequestManager;

describe('HttpRequestManager', function () {
    it('starts with no requests', function () {
        $manager = new HttpRequestManager();
        expect($manager->hasRequests())->toBeFalse();
    });

    it('can add HTTP requests', function () {
        $manager = new HttpRequestManager();
        $called = false;

        $requestId = $manager->addHttpRequest(
            'https://httpbin.org/get',
            [],
            function () use (&$called) {
                $called = true;
            }
        );

        expect($requestId)->toBeString();
        expect($manager->hasRequests())->toBeTrue();
    });

    it('can cancel HTTP requests', function () {
        $manager = new HttpRequestManager();
        $called = false;

        $requestId = $manager->addHttpRequest(
            'https://httpbin.org/delay/10',
            [],
            function ($error) use (&$called) {
                $called = true;
                expect($error)->toContain('cancelled');
            }
        );

        $cancelled = $manager->cancelHttpRequest($requestId);
        expect($cancelled)->toBeTrue();
        expect($called)->toBeTrue();

        expect($manager->cancelHttpRequest('invalid'))->toBeFalse();
    });

    it('provides debug information', function () {
        $manager = new HttpRequestManager();

        $manager->addHttpRequest('https://httpbin.org/get', [], fn () => null);

        $debug = $manager->getDebugInfo();

        expect($debug)->toHaveKeys([
            'pending_count',
            'active_count',
            'by_id_count',
            'active_handles',
            'request_ids',
        ]);

        expect($debug['pending_count'])->toBe(1);
        expect($debug['by_id_count'])->toBe(1);
    });

    it('can clear all requests', function () {
        $manager = new HttpRequestManager();
        $callCount = 0;

        $manager->addHttpRequest('https://httpbin.org/get', [], function () use (&$callCount) {
            $callCount++;
        });

        $manager->addHttpRequest('https://httpbin.org/post', [], function () use (&$callCount) {
            $callCount++;
        });

        expect($manager->hasRequests())->toBeTrue();

        $cleared = $manager->clearAllRequests();

        expect($cleared['pending'])->toBe(2);
        expect($cleared['active'])->toBe(0);
        expect($manager->hasRequests())->toBeFalse();
        expect($callCount)->toBe(2);
    });

    it('can clear pending requests only', function () {
        $manager = new HttpRequestManager();

        $manager->addHttpRequest('https://httpbin.org/get', [], fn () => null);
        $manager->addHttpRequest('https://httpbin.org/post', [], fn () => null);

        $cleared = $manager->clearPendingRequests();

        expect($cleared)->toBe(2);
        expect($manager->hasRequests())->toBeFalse();
    });

    it('processes requests when called', function () {
        if (getenv('CI')) {
            test()->markTestSkipped('Skipped on CI environment');
        }

        $manager = new HttpRequestManager();

        $manager->addHttpRequest('https://httpbin.org/get', [], fn () => null);

        $processed = $manager->processRequests();
        expect($processed)->toBeTrue();

        $debug = $manager->getDebugInfo();
        expect($debug['pending_count'])->toBe(0);
    });
});
