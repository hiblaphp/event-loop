<?php

declare(strict_types=1);

use Hibla\EventLoop\Managers\FileManager;

describe('FileManager', function () {
    it('starts with no work', function () {
        $manager = new FileManager();
        expect($manager->hasWork())->toBeFalse();
        expect($manager->hasPendingOperations())->toBeFalse();
        expect($manager->hasWatchers())->toBeFalse();
    });

    it('can add file operations', function () {
        $manager = new FileManager();
        $tempFile = tempnam(sys_get_temp_dir(), 'test');

        $operationId = $manager->addFileOperation(
            'write',
            $tempFile,
            'test content',
            fn () => null
        );

        expect($operationId)->toBeString();
        expect($manager->hasPendingOperations())->toBeTrue();
        expect($manager->hasWork())->toBeTrue();

        unlink($tempFile);
    });

    it('can cancel file operations', function () {
        $manager = new FileManager();
        $tempFile = tempnam(sys_get_temp_dir(), 'test');

        $operationId = $manager->addFileOperation(
            'write',
            $tempFile,
            'test content',
            fn () => null
        );

        $cancelled = $manager->cancelFileOperation($operationId);
        expect($cancelled)->toBeTrue();

        // Canceling non-existent operation
        expect($manager->cancelFileOperation('invalid'))->toBeFalse();

        unlink($tempFile);
    });

    it('can add file watchers', function () {
        $manager = new FileManager();
        $tempFile = tempnam(sys_get_temp_dir(), 'test');

        $watcherId = $manager->addFileWatcher(
            $tempFile,
            fn () => null
        );

        expect($watcherId)->toBeString();
        expect($manager->hasWatchers())->toBeTrue();
        expect($manager->hasWork())->toBeTrue();

        unlink($tempFile);
    });

    it('can remove file watchers', function () {
        $manager = new FileManager();
        $tempFile = tempnam(sys_get_temp_dir(), 'test');

        $watcherId = $manager->addFileWatcher($tempFile, fn () => null);
        expect($manager->hasWatchers())->toBeTrue();

        $removed = $manager->removeFileWatcher($watcherId);
        expect($removed)->toBeTrue();
        expect($manager->hasWatchers())->toBeFalse();

        // Removing non-existent watcher
        expect($manager->removeFileWatcher('invalid'))->toBeFalse();

        unlink($tempFile);
    });

    it('processes file operations', function () {
        $manager = new FileManager();
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        $called = false;

        $manager->addFileOperation(
            'write',
            $tempFile,
            'test content',
            function () use (&$called) {
                $called = true;
            }
        );

        $processed = $manager->processFileOperations();
        expect($processed)->toBeTrue();
        expect($called)->toBeTrue();
        expect($manager->hasPendingOperations())->toBeFalse();

        unlink($tempFile);
    });

    it('can clear all operations', function () {
        $manager = new FileManager();
        $tempFile1 = tempnam(sys_get_temp_dir(), 'test1');
        $tempFile2 = tempnam(sys_get_temp_dir(), 'test2');

        $manager->addFileOperation('write', $tempFile1, 'data', fn () => null);
        $manager->addFileWatcher($tempFile2, fn () => null);

        expect($manager->hasWork())->toBeTrue();

        $manager->clearAllOperations();

        expect($manager->hasWork())->toBeFalse();
        expect($manager->hasPendingOperations())->toBeFalse();
        expect($manager->hasWatchers())->toBeFalse();

        unlink($tempFile1);
        unlink($tempFile2);
    });

    it('handles operation with options', function () {
        $manager = new FileManager();
        $tempFile = tempnam(sys_get_temp_dir(), 'test');

        $operationId = $manager->addFileOperation(
            'read',
            $tempFile,
            null,
            fn () => null,
            ['mode' => 'r', 'lock' => true]
        );

        expect($operationId)->toBeString();
        expect($manager->hasPendingOperations())->toBeTrue();

        unlink($tempFile);
    });
});
