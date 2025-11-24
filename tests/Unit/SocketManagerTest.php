<?php

declare(strict_types=1);

use Hibla\EventLoop\Managers\SocketManager;

describe('SocketManager', function () {
    it('starts with no watchers', function () {
        $manager = new SocketManager();
        expect($manager->hasWatchers())->toBeFalse();
    });

    it('can add read watchers', function () {
        $manager = new SocketManager();
        [$socket1, $socket2] = createTestSocketPair();
        $called = false;

        $manager->addReadWatcher($socket1, function () use (&$called) {
            $called = true;
        });

        expect($manager->hasWatchers())->toBeTrue();

        fwrite($socket2, 'test');

        $processed = $manager->processSockets();
        expect($processed)->toBeTrue();
        expect($called)->toBeTrue();

        fclose($socket1);
        fclose($socket2);
    });

    it('can add write watchers', function () {
        $manager = new SocketManager();
        [$socket1, $socket2] = createTestSocketPair();
        $called = false;

        $manager->addWriteWatcher($socket1, function () use (&$called) {
            $called = true;
        });

        expect($manager->hasWatchers())->toBeTrue();

        $processed = $manager->processSockets();
        expect($processed)->toBeTrue();
        expect($called)->toBeTrue();

        fclose($socket1);
        fclose($socket2);
    });

    it('can remove watchers', function () {
        $manager = new SocketManager();
        [$socket1, $socket2] = createTestSocketPair();

        $manager->addReadWatcher($socket1, fn () => null);
        $manager->addWriteWatcher($socket1, fn () => null);

        expect($manager->hasWatchers())->toBeTrue();

        $manager->removeReadWatcher($socket1);
        $manager->removeWriteWatcher($socket1);

        expect($manager->hasWatchers())->toBeFalse();

        fclose($socket1);
        fclose($socket2);
    });

    it('handles multiple watchers per socket', function () {
        $manager = new SocketManager();
        [$socket1, $socket2] = createTestSocketPair();
        $callCount = 0;

        // Add multiple read watchers for same socket
        $manager->addReadWatcher($socket1, function () use (&$callCount) {
            $callCount++;
        });
        $manager->addReadWatcher($socket1, function () use (&$callCount) {
            $callCount++;
        });

        // Make socket readable
        fwrite($socket2, 'test');

        $manager->processSockets();

        expect($callCount)->toBe(2);

        fclose($socket1);
        fclose($socket2);
    });

    it('handles callback exceptions gracefully', function () {
        $manager = new SocketManager();
        [$socket1, $socket2] = createTestSocketPair();
        $goodCallbackExecuted = false;

        $manager->addReadWatcher($socket1, function () {
            throw new Exception('Test exception');
        });

        $manager->addReadWatcher($socket1, function () use (&$goodCallbackExecuted) {
            $goodCallbackExecuted = true;
        });

        fwrite($socket2, 'test');

        $processed = $manager->processSockets();
        expect($processed)->toBeTrue();
        expect($goodCallbackExecuted)->toBeTrue();

        fclose($socket1);
        fclose($socket2);
    });

    it('can clear all watchers', function () {
        $manager = new SocketManager();
        [$socket1, $socket2] = createTestSocketPair();

        $manager->addReadWatcher($socket1, fn () => null);
        $manager->addWriteWatcher($socket1, fn () => null);

        expect($manager->hasWatchers())->toBeTrue();

        $manager->clearAllWatchers();

        expect($manager->hasWatchers())->toBeFalse();

        fclose($socket1);
        fclose($socket2);
    });

    it('can clear watchers for specific socket', function () {
        $manager = new SocketManager();
        [$socket1, $socket2] = createTestSocketPair();
        [$socket3, $socket4] = createTestSocketPair();

        $manager->addReadWatcher($socket1, fn () => null);
        $manager->addWriteWatcher($socket1, fn () => null);
        $manager->addReadWatcher($socket3, fn () => null);

        expect($manager->hasWatchers())->toBeTrue();

        $manager->clearAllWatchersForSocket($socket1);

        expect($manager->hasWatchers())->toBeTrue(); // socket3 watcher should remain

        fclose($socket1);
        fclose($socket2);
        fclose($socket3);
        fclose($socket4);
    });
});
