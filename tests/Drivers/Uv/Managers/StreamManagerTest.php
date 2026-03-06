<?php

declare(strict_types=1);

use Hibla\EventLoop\Drivers\Uv\Managers\StreamManager;

describe('StreamManager (UV)', function () {
    it('starts with no watchers', function () {
        $loop = \uv_loop_new();
        $manager = new StreamManager($loop);

        expect($manager->hasWatchers())->toBeFalse();
    });

    it('can add read stream watchers', function () {
        $loop = \uv_loop_new();
        $manager = new StreamManager($loop);
        [$client, $server] = createTcpSocketPair();

        $watcherId = $manager->addReadWatcher($server, fn () => null);

        expect($watcherId)->toBeString();
        expect($manager->hasWatchers())->toBeTrue();

        $manager->clearAllWatchers();
        fclose($client);
        fclose($server);
    });

    it('can add write stream watchers', function () {
        $loop = \uv_loop_new();
        $manager = new StreamManager($loop);
        [$client, $server] = createTcpSocketPair();

        $watcherId = $manager->addWriteWatcher($client, fn () => null);

        expect($watcherId)->toBeString();
        expect($manager->hasWatchers())->toBeTrue();

        $manager->clearAllWatchers();
        fclose($client);
        fclose($server);
    });

    it('can remove read stream watchers by watcher ID', function () {
        $loop = \uv_loop_new();
        $manager = new StreamManager($loop);
        [$client, $server] = createTcpSocketPair();

        $watcherId = $manager->addReadWatcher($server, fn () => null);
        expect($manager->hasWatchers())->toBeTrue();

        $removed = $manager->removeReadWatcher($watcherId);
        expect($removed)->toBeTrue();
        expect($manager->hasWatchers())->toBeFalse();

        fclose($client);
        fclose($server);
    });

    it('can remove write stream watchers by watcher ID', function () {
        $loop = \uv_loop_new();
        $manager = new StreamManager($loop);
        [$client, $server] = createTcpSocketPair();

        $watcherId = $manager->addWriteWatcher($client, fn () => null);
        expect($manager->hasWatchers())->toBeTrue();

        $removed = $manager->removeWriteWatcher($watcherId);
        expect($removed)->toBeTrue();
        expect($manager->hasWatchers())->toBeFalse();

        fclose($client);
        fclose($server);
    });

    it('returns false when removing non-existent read watcher', function () {
        $loop = \uv_loop_new();
        $manager = new StreamManager($loop);

        expect($manager->removeReadWatcher('non-existent-id'))->toBeFalse();
    });

    it('returns false when removing non-existent write watcher', function () {
        $loop = \uv_loop_new();
        $manager = new StreamManager($loop);

        expect($manager->removeWriteWatcher('non-existent-id'))->toBeFalse();
    });

    it('throws exception when removeReadWatcher receives a write watcher ID', function () {
        $loop = \uv_loop_new();
        $manager = new StreamManager($loop);
        [$client, $server] = createTcpSocketPair();

        $writeWatcherId = $manager->addWriteWatcher($client, fn () => null);

        expect(fn () => $manager->removeReadWatcher($writeWatcherId))
            ->toThrow(InvalidArgumentException::class, 'is not a READ watcher')
        ;

        $manager->clearAllWatchers();
        fclose($client);
        fclose($server);
    });

    it('throws exception when removeWriteWatcher receives a read watcher ID', function () {
        $loop = \uv_loop_new();
        $manager = new StreamManager($loop);
        [$client, $server] = createTcpSocketPair();

        $readWatcherId = $manager->addReadWatcher($server, fn () => null);

        expect(fn () => $manager->removeWriteWatcher($readWatcherId))
            ->toThrow(InvalidArgumentException::class, 'is not a WRITE watcher')
        ;

        $manager->clearAllWatchers();
        fclose($client);
        fclose($server);
    });

    it('invokes read callback when data is available on a stream', function () {
        $loop = \uv_loop_new();
        $manager = new StreamManager($loop);
        [$client, $server] = createTcpSocketPair();
        $called = false;

        fwrite($client, 'hello');
        fflush($client);

        $watcherId = null;
        $watcherId = $manager->addReadWatcher($server, function () use (&$called, &$watcherId, $manager) {
            $called = true;
            $manager->removeReadWatcher($watcherId);
        });

        \uv_run($loop, UV::RUN_ONCE);

        expect($called)->toBeTrue();

        fclose($client);
        fclose($server);
    });

    it('invokes write callback when a stream is ready for writing', function () {
        $loop = \uv_loop_new();
        $manager = new StreamManager($loop);
        [$client, $server] = createTcpSocketPair();
        $called = false;

        $watcherId = null;
        $watcherId = $manager->addWriteWatcher($client, function () use (&$called, &$watcherId, $manager) {
            $called = true;
            $manager->removeWriteWatcher($watcherId);
        });

        \uv_run($loop, UV::RUN_ONCE);

        expect($called)->toBeTrue();

        fclose($client);
        fclose($server);
    });

    it('can clear all watchers', function () {
        $loop = \uv_loop_new();
        $manager = new StreamManager($loop);
        [$client, $server] = createTcpSocketPair();

        $manager->addReadWatcher($server, fn () => null);
        $manager->addWriteWatcher($client, fn () => null);

        expect($manager->hasWatchers())->toBeTrue();

        $manager->clearAllWatchers();

        expect($manager->hasWatchers())->toBeFalse();

        fclose($client);
        fclose($server);
    });

    it('can remove specific watchers without affecting others', function () {
        $loop = \uv_loop_new();
        $manager = new StreamManager($loop);
        [$client1, $server1] = createTcpSocketPair();
        [$client2, $server2] = createTcpSocketPair();

        $watcherId1 = $manager->addReadWatcher($server1, fn () => null);
        $watcherId2 = $manager->addReadWatcher($server2, fn () => null);

        expect($manager->hasWatchers())->toBeTrue();

        $manager->removeReadWatcher($watcherId1);
        expect($manager->hasWatchers())->toBeTrue();

        $manager->removeReadWatcher($watcherId2);
        expect($manager->hasWatchers())->toBeFalse();

        fclose($client1);
        fclose($server1);
        fclose($client2);
        fclose($server2);
    });

    it('can use removeStreamWatcher as a generic removal method', function () {
        $loop = \uv_loop_new();
        $manager = new StreamManager($loop);
        [$client, $server] = createTcpSocketPair();

        $readWatcherId = $manager->addReadWatcher($server, fn () => null);
        $writeWatcherId = $manager->addWriteWatcher($client, fn () => null);

        expect($manager->hasWatchers())->toBeTrue();

        expect($manager->removeStreamWatcher($readWatcherId))->toBeTrue();
        expect($manager->removeStreamWatcher($writeWatcherId))->toBeTrue();
        expect($manager->hasWatchers())->toBeFalse();

        fclose($client);
        fclose($server);
    });

    it('processStreams is a no-op and returns false in the UV driver', function () {
        $loop = \uv_loop_new();
        $manager = new StreamManager($loop);

        expect($manager->processStreams())->toBeFalse();
    });
})->skip(fn () => ! extension_loaded('uv'), 'uv extension required');
