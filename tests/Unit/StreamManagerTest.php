<?php

declare(strict_types=1);

use Hibla\EventLoop\Managers\StreamManager;

describe('StreamManager', function () {
    it('starts with no watchers', function () {
        $manager = new StreamManager();
        expect($manager->hasWatchers())->toBeFalse();
    });

    it('can add stream watchers', function () {
        $manager = new StreamManager();
        $stream = createTestStream();

        $watcherId = $manager->addStreamWatcher($stream, fn () => null);

        expect($watcherId)->toBeString();
        expect($manager->hasWatchers())->toBeTrue();

        fclose($stream);
    });

    it('can remove stream watchers', function () {
        $manager = new StreamManager();
        $stream = createTestStream();

        $watcherId = $manager->addStreamWatcher($stream, fn () => null);
        expect($manager->hasWatchers())->toBeTrue();

        $removed = $manager->removeStreamWatcher($watcherId);
        expect($removed)->toBeTrue();
        expect($manager->hasWatchers())->toBeFalse();

        // Removing non-existent watcher
        expect($manager->removeStreamWatcher('invalid'))->toBeFalse();

        fclose($stream);
    });

    it('processes ready streams', function () {
        $manager = new StreamManager();
        $stream = createTestStream();
        $called = false;

        // Write data to make stream readable
        fwrite($stream, 'test data');
        rewind($stream);

        $manager->addStreamWatcher($stream, function () use (&$called) {
            $called = true;
        });

        $manager->processStreams();

        expect($called)->toBeTrue();

        fclose($stream);
    });

    it('handles multiple stream types', function () {
        $manager = new StreamManager();
        $stream1 = createTestStream();
        $stream2 = createTestStream();

        $read1Called = false;
        $read2Called = false;

        // Prepare both streams with data
        fwrite($stream1, 'data1');
        fwrite($stream2, 'data2');
        rewind($stream1);
        rewind($stream2);

        $manager->addStreamWatcher($stream1, function () use (&$read1Called) {
            $read1Called = true;
        }, 'read');

        $manager->addStreamWatcher($stream2, function () use (&$read2Called) {
            $read2Called = true;
        }, 'read');

        $manager->processStreams();

        expect($read1Called)->toBeTrue();
        expect($read2Called)->toBeTrue();

        fclose($stream1);
        fclose($stream2);
    });

    it('can clear all watchers', function () {
        $manager = new StreamManager();
        $stream1 = createTestStream();
        $stream2 = createTestStream();

        $manager->addStreamWatcher($stream1, fn () => null);
        $manager->addStreamWatcher($stream2, fn () => null);

        expect($manager->hasWatchers())->toBeTrue();

        $manager->clearAllWatchers();

        expect($manager->hasWatchers())->toBeFalse();

        fclose($stream1);
        fclose($stream2);
    });
});
