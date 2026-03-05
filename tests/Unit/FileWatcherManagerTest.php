<?php

declare(strict_types=1);

use Hibla\EventLoop\Managers\FileWatcherManager;

describe('FileWatcherManager', function () {
    it('starts with no watchers', function () {
        $manager = new FileWatcherManager();
        expect($manager->hasWatchers())->toBeFalse();
    });

    it('can add a file watcher and returns a string ID', function () {
        $manager = new FileWatcherManager();

        $watcherId = $manager->addFileWatcher(
            '/tmp/test-file.txt',
            fn () => null
        );

        expect($watcherId)->toBeString()->not->toBeEmpty();
        expect($manager->hasWatchers())->toBeTrue();
    });

    it('returns unique IDs for each watcher', function () {
        $manager = new FileWatcherManager();

        $first  = $manager->addFileWatcher('/tmp/file-a.txt', fn () => null);
        $second = $manager->addFileWatcher('/tmp/file-b.txt', fn () => null);

        expect($first)->not->toBe($second);
    });

    it('can remove a watcher by ID', function () {
        $manager = new FileWatcherManager();

        $watcherId = $manager->addFileWatcher('/tmp/test-file.txt', fn () => null);

        expect($manager->removeFileWatcher($watcherId))->toBeTrue();
        expect($manager->hasWatchers())->toBeFalse();
    });

    it('returns false when removing a non-existent watcher', function () {
        $manager = new FileWatcherManager();

        expect($manager->removeFileWatcher('non-existent-id'))->toBeFalse();
    });

    it('only removes the targeted watcher, leaving others intact', function () {
        $manager = new FileWatcherManager();

        $first  = $manager->addFileWatcher('/tmp/file-a.txt', fn () => null);
        $second = $manager->addFileWatcher('/tmp/file-b.txt', fn () => null);

        $manager->removeFileWatcher($first);

        expect($manager->hasWatchers())->toBeTrue();
        expect($manager->removeFileWatcher($second))->toBeTrue();
        expect($manager->removeFileWatcher($first))->toBeFalse();
    });

    it('can clear all watchers at once', function () {
        $manager = new FileWatcherManager();

        $manager->addFileWatcher('/tmp/file-a.txt', fn () => null);
        $manager->addFileWatcher('/tmp/file-b.txt', fn () => null);

        expect($manager->hasWatchers())->toBeTrue();

        $manager->clearAllWatchers();

        expect($manager->hasWatchers())->toBeFalse();
    });

    it('returns false from processWatchers when there is nothing to process', function () {
        $manager = new FileWatcherManager();

        expect($manager->processWatchers())->toBeFalse();
    });

    it('invokes the callback with the correct event type when a file is modified', function () {
        $file = tempnam(sys_get_temp_dir(), 'hibla_test_');

        $manager  = new FileWatcherManager();
        $events   = [];

        $manager->addFileWatcher(
            $file,
            function (string $eventType, string $path) use (&$events) {
                $events[] = compact('eventType', 'path');
            },
            ['interval' => 0]
        );

        // Trigger a modification.
        sleep(1);
        file_put_contents($file, 'changed');

        $manager->processWatchers();

        expect($events)->toHaveCount(1);
        expect($events[0]['eventType'])->toBe('modified');
        expect($events[0]['path'])->toBe($file);

        unlink($file);
    });

    it('invokes the callback with deleted event type when a file is removed', function () {
        $file = tempnam(sys_get_temp_dir(), 'hibla_test_');

        $manager = new FileWatcherManager();
        $events  = [];

        $manager->addFileWatcher(
            $file,
            function (string $eventType, string $path) use (&$events) {
                $events[] = compact('eventType', 'path');
            },
            ['interval' => 0]
        );

        sleep(1);
        unlink($file);

        $manager->processWatchers();

        expect($events)->toHaveCount(1);
        expect($events[0]['eventType'])->toBe('deleted');
    });

    it('does not invoke the callback when a file has not changed', function () {
        $file = tempnam(sys_get_temp_dir(), 'hibla_test_');

        $manager  = new FileWatcherManager();
        $called   = false;

        $manager->addFileWatcher(
            $file,
            function () use (&$called) { $called = true; },
            ['interval' => 0]
        );

        $manager->processWatchers();

        expect($called)->toBeFalse();

        unlink($file);
    });
});