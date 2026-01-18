<?php

declare(strict_types=1);

use Hibla\EventLoop\Managers\StreamManager;

describe('StreamManager', function () {
    it('starts with no watchers', function () {
        $manager = new StreamManager();
        expect($manager->hasWatchers())->toBeFalse();
    });

    it('can add read stream watchers', function () {
        $manager = new StreamManager();
        $stream = createTestStream();

        $watcherId = $manager->addReadWatcher($stream, fn () => null);

        expect($watcherId)->toBeString();
        expect($manager->hasWatchers())->toBeTrue();

        fclose($stream);
    });

    it('can add write stream watchers', function () {
        $manager = new StreamManager();
        [$client, $server] = createTcpSocketPair();

        $watcherId = $manager->addWriteWatcher($client, fn () => null);

        expect($watcherId)->toBeString();
        expect($manager->hasWatchers())->toBeTrue();

        fclose($client);
        fclose($server);
    });

    it('can remove read stream watchers', function () {
        $manager = new StreamManager();
        $stream = createTestStream();

        $watcherId = $manager->addReadWatcher($stream, fn () => null);
        expect($manager->hasWatchers())->toBeTrue();

        $removed = $manager->removeReadWatcher($stream);
        expect($removed)->toBeTrue();
        expect($manager->hasWatchers())->toBeFalse();

        fclose($stream);
    });

    it('can remove write stream watchers', function () {
        $manager = new StreamManager();
        [$client, $server] = createTcpSocketPair();

        $watcherId = $manager->addWriteWatcher($client, fn () => null);
        expect($manager->hasWatchers())->toBeTrue();

        $removed = $manager->removeWriteWatcher($client);
        expect($removed)->toBeTrue();
        expect($manager->hasWatchers())->toBeFalse();

        fclose($client);
        fclose($server);
    });

    it('returns false when removing non-existent read watcher', function () {
        $manager = new StreamManager();
        $stream = createTestStream();

        $result = $manager->removeReadWatcher($stream);
        expect($result)->toBeFalse();

        fclose($stream);
    });

    it('returns false when removing non-existent write watcher', function () {
        $manager = new StreamManager();
        [$client, $server] = createTcpSocketPair();

        $result = $manager->removeWriteWatcher($client);
        expect($result)->toBeFalse();

        fclose($client);
        fclose($server);
    });

    it('processes ready read streams', function () {
        $manager = new StreamManager();
        $stream = createTestStream();
        $called = false;

        fwrite($stream, 'test data');
        rewind($stream);

        $manager->addReadWatcher($stream, function () use (&$called) {
            $called = true;
        });

        $manager->processStreams();

        expect($called)->toBeTrue();

        fclose($stream);
    });

    it('handles multiple read streams', function () {
        $manager = new StreamManager();
        $stream1 = createTestStream();
        $stream2 = createTestStream();

        $read1Called = false;
        $read2Called = false;

        fwrite($stream1, 'data1');
        fwrite($stream2, 'data2');
        rewind($stream1);
        rewind($stream2);

        $manager->addReadWatcher($stream1, function () use (&$read1Called) {
            $read1Called = true;
        });

        $manager->addReadWatcher($stream2, function () use (&$read2Called) {
            $read2Called = true;
        });

        $manager->processStreams();

        expect($read1Called)->toBeTrue();
        expect($read2Called)->toBeTrue();

        fclose($stream1);
        fclose($stream2);
    });

    it('handles write streams', function () {
        $manager = new StreamManager();
        [$client, $server] = createTcpSocketPair();
        $called = false;

        $manager->addWriteWatcher($client, function () use (&$called) {
            $called = true;
        });

        $manager->processStreams();

        expect($called)->toBeTrue();

        fclose($client);
        fclose($server);
    });

    it('handles readable streams from network data', function () {
        $manager = new StreamManager();
        [$client, $server] = createTcpSocketPair();
        $called = false;

        $manager->addReadWatcher($server, function () use (&$called) {
            $called = true;
        });

        fwrite($client, 'hello');
        fflush($client);

        $manager->processStreams();

        expect($called)->toBeTrue();

        fclose($client);
        fclose($server);
    });

    it('can clear all watchers', function () {
        $manager = new StreamManager();
        $stream1 = createTestStream();
        [$client, $server] = createTcpSocketPair();

        $manager->addReadWatcher($stream1, fn () => null);
        $manager->addWriteWatcher($client, fn () => null);

        expect($manager->hasWatchers())->toBeTrue();

        $manager->clearAllWatchers();

        expect($manager->hasWatchers())->toBeFalse();

        fclose($stream1);
        fclose($client);
        fclose($server);
    });

    it('supports mixed read and write watchers', function () {
        $manager = new StreamManager();
        $readStream = createTestStream();
        [$client, $server] = createTcpSocketPair();

        $readCalled = false;
        $writeCalled = false;

        fwrite($readStream, 'test data');
        rewind($readStream);

        $manager->addReadWatcher($readStream, function () use (&$readCalled) {
            $readCalled = true;
        });

        $manager->addWriteWatcher($client, function () use (&$writeCalled) {
            $writeCalled = true;
        });

        $manager->processStreams();

        expect($readCalled)->toBeTrue();
        expect($writeCalled)->toBeTrue();

        fclose($readStream);
        fclose($client);
        fclose($server);
    });
});