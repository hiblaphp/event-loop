<?php

declare(strict_types=1);

use Hibla\EventLoop\EventLoopFactory;

function setupCancellationTest(): void
{
    EventLoopFactory::reset();

    $tempDir = __DIR__ . '/../temp';
    if (! is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
}

function teardownCancellationTest(array $testFiles): void
{
    foreach ($testFiles as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
    EventLoopFactory::reset();
}

function runAsyncTest(callable $testCallback, callable $assertionCallback, float $timeout = 0.2): void
{
    $testCallback();

    EventLoopFactory::getInstance()->addTimer($timeout, function () use ($assertionCallback) {
        $assertionCallback();
        EventLoopFactory::getInstance()->stop();
    });

    EventLoopFactory::getInstance()->run();
}

function captureOperationResult(): array
{
    return [
        'callbackExecuted' => false,
        'error' => null,
        'result' => null,
        'cancelled' => false,
    ];
}

describe('Mid-Flight Stream Cancellation', function () {

    it('can cancel streaming write mid-flight', function () {
        setupCancellationTest();
        $testFiles = [];

        try {
            $testFile = __DIR__ . '/../temp/test_midstream_write.txt';
            $testFiles[] = $testFile;

            if (file_exists($testFile)) {
                unlink($testFile);
            }

            $largeData = str_repeat('ABCDEFGHIJ', 100000); // 1MB
            $dataSize = strlen($largeData);
            $state = captureOperationResult();

            runAsyncTest(
                function () use ($testFile, $largeData, &$state) {
                    $operationId = EventLoopFactory::getInstance()->addFileOperation(
                        'write',
                        $testFile,
                        $largeData,
                        function ($error, $result) use (&$state) {
                            $state['callbackExecuted'] = true;
                            $state['error'] = $error;
                            $state['result'] = $result;
                        },
                        ['use_streaming' => true]
                    );

                    EventLoopFactory::getInstance()->addTimer(0.005, function () use ($operationId, &$state) {
                        $state['cancelled'] = EventLoopFactory::getInstance()->cancelFileOperation($operationId);
                    });
                },
                function () use ($testFile, $dataSize, &$state) {
                    if (! $state['cancelled']) {
                        expect($state['callbackExecuted'])->toBeTrue('Operation completed before cancellation');
                    } else {
                        expect($state['cancelled'])->toBeTrue('Operation should be cancelled');
                        expect($state['callbackExecuted'])->toBeFalse('Callback should not execute for cancelled operation');

                        if (file_exists($testFile)) {
                            $finalSize = filesize($testFile);
                            expect($finalSize)->toBeLessThan($dataSize, 'File should be partial');
                        }
                    }
                },
                0.2
            );
        } finally {
            teardownCancellationTest($testFiles);
        }
    });

    it('can cancel streaming read mid-flight', function () {
        setupCancellationTest();
        $testFiles = [];

        try {
            $testFile = __DIR__ . '/../temp/test_midstream_read.txt';
            $testFiles[] = $testFile;

            $largeContent = str_repeat('LINE_' . str_repeat('X', 95) . "\n", 20000);
            file_put_contents($testFile, $largeContent);
            $fileSize = filesize($testFile);

            $state = captureOperationResult();

            runAsyncTest(
                function () use ($testFile, &$state) {
                    $operationId = EventLoopFactory::getInstance()->addFileOperation(
                        'read',
                        $testFile,
                        null,
                        function ($error, $result) use (&$state) {
                            $state['callbackExecuted'] = true;
                            $state['error'] = $error;
                            $state['result'] = $result;
                        },
                        ['use_streaming' => true]
                    );

                    EventLoopFactory::getInstance()->addTimer(0.005, function () use ($operationId, &$state) {
                        $state['cancelled'] = EventLoopFactory::getInstance()->cancelFileOperation($operationId);
                    });
                },
                function () use ($fileSize, &$state) {
                    if (! $state['cancelled']) {
                        expect($state['callbackExecuted'])->toBeTrue('Operation completed before cancellation');
                    } else {
                        expect($state['cancelled'])->toBeTrue('Operation should be cancelled');

                        if ($state['callbackExecuted']) {
                            $bytesRead = strlen($state['result'] ?? '');
                            expect($bytesRead)->toBeLessThan($fileSize, 'Read should be partial');
                        } else {
                            expect($state['callbackExecuted'])->toBeFalse('Callback should not execute');
                        }
                    }
                },
                0.2
            );
        } finally {
            teardownCancellationTest($testFiles);
        }
    });

    it('can cancel streaming copy mid-flight', function () {
        setupCancellationTest();
        $testFiles = [];

        try {
            $testSource = __DIR__ . '/../temp/test_midstream_copy_source.txt';
            $testDest = __DIR__ . '/../temp/test_midstream_copy_dest.txt';
            $testFiles[] = $testSource;
            $testFiles[] = $testDest;

            $sourceContent = str_repeat('COPY_DATA_', 150000);
            file_put_contents($testSource, $sourceContent);
            $sourceSize = filesize($testSource);

            if (file_exists($testDest)) {
                unlink($testDest);
            }

            $state = captureOperationResult();

            runAsyncTest(
                function () use ($testSource, $testDest, &$state) {
                    $operationId = EventLoopFactory::getInstance()->addFileOperation(
                        'copy',
                        $testSource,
                        $testDest,
                        function ($error, $result) use (&$state) {
                            $state['callbackExecuted'] = true;
                            $state['error'] = $error;
                            $state['result'] = $result;
                        },
                        ['use_streaming' => true]
                    );

                    EventLoopFactory::getInstance()->addTimer(0.005, function () use ($operationId, &$state) {
                        $state['cancelled'] = EventLoopFactory::getInstance()->cancelFileOperation($operationId);
                    });
                },
                function () use ($testDest, $sourceSize, &$state) {
                    if (! $state['cancelled']) {
                        expect($state['callbackExecuted'])->toBeTrue('Operation completed before cancellation');
                    } else {
                        expect($state['cancelled'])->toBeTrue('Operation should be cancelled');
                        expect($state['callbackExecuted'])->toBeFalse('Callback should not execute');

                        if (file_exists($testDest)) {
                            $destSize = filesize($testDest);
                            expect($destSize)->toBeLessThan($sourceSize, 'Copy should be partial');
                        }
                    }
                },
                0.2
            );
        } finally {
            teardownCancellationTest($testFiles);
        }
    });

    it('can cancel generator write mid-flight', function () {
        setupCancellationTest();
        $testFiles = [];

        try {
            $testFile = __DIR__ . '/../temp/test_midstream_generator.txt';
            $testFiles[] = $testFile;

            if (file_exists($testFile)) {
                unlink($testFile);
            }

            $chunksYielded = 0;
            $state = captureOperationResult();

            runAsyncTest(
                function () use ($testFile, &$chunksYielded, &$state) {
                    $generator = (function () use (&$chunksYielded) {
                        for ($i = 0; $i < 1000; $i++) {
                            $chunksYielded++;
                            yield "CHUNK_$i:" . str_repeat('DATA', 250) . "\n";
                        }
                    })();

                    $operationId = EventLoopFactory::getInstance()->addFileOperation(
                        'write_generator',
                        $testFile,
                        $generator,
                        function ($error, $result) use (&$state) {
                            $state['callbackExecuted'] = true;
                            $state['error'] = $error;
                            $state['result'] = $result;
                        }
                    );

                    EventLoopFactory::getInstance()->nextTick(function () use ($operationId, &$state) {
                        $state['cancelled'] = EventLoopFactory::getInstance()->cancelFileOperation($operationId);
                    });
                },
                function () use ($testFile, &$chunksYielded, &$state) {
                    expect($state['cancelled'])->toBeTrue('Generator operation should be cancelled');
                    expect($state['callbackExecuted'])->toBeFalse('Callback should not execute');

                    if (file_exists($testFile)) {
                        $finalSize = filesize($testFile);
                        $expectedFullSize = 1000 * 1004;
                        expect($finalSize)->toBeLessThan($expectedFullSize, 'Generator write should be incomplete');
                    }

                    expect($chunksYielded)->toBeLessThan(1000, 'Not all chunks should be yielded');
                },
                0.2
            );
        } finally {
            teardownCancellationTest($testFiles);
        }
    });

    it('can handle immediate/rapid cancellation', function () {
        setupCancellationTest();
        $testFiles = [];

        try {
            $testFile = __DIR__ . '/../temp/test_rapid_cancel.txt';
            $testFiles[] = $testFile;

            if (file_exists($testFile)) {
                unlink($testFile);
            }

            $rapidData = str_repeat('RAPID', 200000); // 1MB
            $state = captureOperationResult();

            $operationId = EventLoopFactory::getInstance()->addFileOperation(
                'write',
                $testFile,
                $rapidData,
                function ($error, $result) use (&$state) {
                    $state['callbackExecuted'] = true;
                },
                ['use_streaming' => true]
            );

            $state['cancelled'] = EventLoopFactory::getInstance()->cancelFileOperation($operationId);

            runAsyncTest(
                function () {},
                function () use ($testFile, &$state) {
                    expect($state['cancelled'])->toBeTrue('Immediate cancellation should succeed');
                    expect(file_exists($testFile))->toBeFalse('File should not exist');
                    expect($state['callbackExecuted'])->toBeFalse('Callback should not execute');
                },
                0.1
            );
        } finally {
            teardownCancellationTest($testFiles);
        }
    })->group('cancellation', 'rapid');

    it('prevents callback execution for cancelled operations', function () {
        setupCancellationTest();
        $testFiles = [];

        try {
            $testFile = __DIR__ . '/../temp/test_no_callback.txt';
            $testFiles[] = $testFile;

            $state = captureOperationResult();

            $operationId = EventLoopFactory::getInstance()->addFileOperation(
                'write',
                $testFile,
                str_repeat('DATA', 100000),
                function ($error, $result) use (&$state) {
                    $state['callbackExecuted'] = true;
                    $state['error'] = $error;
                },
                ['use_streaming' => true]
            );

            $state['cancelled'] = EventLoopFactory::getInstance()->cancelFileOperation($operationId);

            runAsyncTest(
                function () {},
                function () use (&$state) {
                    expect($state['cancelled'])->toBeTrue();
                    expect($state['callbackExecuted'])->toBeFalse();
                },
                0.1
            );
        } finally {
            teardownCancellationTest($testFiles);
        }
    });

    it('handles partial data correctly after cancellation', function () {
        setupCancellationTest();
        $testFiles = [];

        try {
            $testFile = __DIR__ . '/../temp/test_partial_data.txt';
            $testFiles[] = $testFile;

            $largeData = str_repeat('PARTIAL_', 125000); // 1MB
            $dataSize = strlen($largeData);
            $state = captureOperationResult();

            runAsyncTest(
                function () use ($testFile, $largeData, &$state) {
                    $operationId = EventLoopFactory::getInstance()->addFileOperation(
                        'write',
                        $testFile,
                        $largeData,
                        function ($error, $result) use (&$state) {
                            $state['callbackExecuted'] = true;
                        },
                        ['use_streaming' => true]
                    );

                    EventLoopFactory::getInstance()->addTimer(0.005, function () use ($operationId, &$state) {
                        $state['cancelled'] = EventLoopFactory::getInstance()->cancelFileOperation($operationId);
                    });
                },
                function () use ($testFile, $dataSize, &$state) {
                    if (! $state['cancelled']) {
                        expect($state['callbackExecuted'])->toBeTrue('Operation completed before cancellation');
                    } else {
                        expect($state['cancelled'])->toBeTrue('Operation should be cancelled');

                        if (file_exists($testFile)) {
                            $partialSize = filesize($testFile);
                            expect($partialSize)->toBeGreaterThan(0, 'Should have some partial data');
                            expect($partialSize)->toBeLessThan($dataSize, 'Should not be complete');
                        }
                    }
                },
                0.2
            );
        } finally {
            teardownCancellationTest($testFiles);
        }
    })->group('cancellation', 'partial-data');

    it('can cancel multiple operations independently', function () {
        setupCancellationTest();
        $testFiles = [];

        try {
            $testFile1 = __DIR__ . '/../temp/test_multi_cancel_1.txt';
            $testFile2 = __DIR__ . '/../temp/test_multi_cancel_2.txt';
            $testFile3 = __DIR__ . '/../temp/test_multi_cancel_3.txt';

            $testFiles[] = $testFile1;
            $testFiles[] = $testFile2;
            $testFiles[] = $testFile3;

            $data = str_repeat('DATA', 100000);

            $state1 = captureOperationResult();
            $state2 = captureOperationResult();
            $state3 = captureOperationResult();

            runAsyncTest(
                function () use ($testFile1, $testFile2, $testFile3, $data, &$state1, &$state2, &$state3) {
                    $op1 = EventLoopFactory::getInstance()->addFileOperation(
                        'write',
                        $testFile1,
                        $data,
                        function ($error) use (&$state1) { $state1['callbackExecuted'] = true; },
                        ['use_streaming' => true]
                    );

                    EventLoopFactory::getInstance()->addFileOperation(
                        'write',
                        $testFile2,
                        $data,
                        function ($error) use (&$state2) { $state2['callbackExecuted'] = true; },
                        ['use_streaming' => true]
                    );

                    $op3 = EventLoopFactory::getInstance()->addFileOperation(
                        'write',
                        $testFile3,
                        $data,
                        function ($error) use (&$state3) { $state3['callbackExecuted'] = true; },
                        ['use_streaming' => true]
                    );

                    EventLoopFactory::getInstance()->nextTick(function () use ($op1, $op3, &$state1, &$state3) {
                        $state1['cancelled'] = EventLoopFactory::getInstance()->cancelFileOperation($op1);
                        $state3['cancelled'] = EventLoopFactory::getInstance()->cancelFileOperation($op3);
                    });
                },
                function () use (&$state1, &$state2, &$state3) {
                    expect($state1['cancelled'])->toBeTrue('Operation 1 should be cancelled');
                    expect($state1['callbackExecuted'])->toBeFalse('Operation 1 callback should not execute');

                    expect($state3['cancelled'])->toBeTrue('Operation 3 should be cancelled');
                    expect($state3['callbackExecuted'])->toBeFalse('Operation 3 callback should not execute');

                    expect($state2['callbackExecuted'])->toBeTrue('Operation 2 should complete');
                },
                0.25
            );
        } finally {
            teardownCancellationTest($testFiles);
        }
    });

    it('returns false when cancelling non-existent operation', function () {
        setupCancellationTest();

        try {
            $result = EventLoopFactory::getInstance()->cancelFileOperation('non-existent-id');
            expect($result)->toBeFalse();
        } finally {
            teardownCancellationTest([]);
        }
    });

    it('returns false when cancelling already completed operation', function () {
        setupCancellationTest();
        $testFiles = [];

        try {
            $testFile = __DIR__ . '/../temp/test_completed_cancel.txt';
            $testFiles[] = $testFile;

            $state = [
                'completed' => false,
                'cancelResult' => null,
                'operationId' => null,
            ];

            runAsyncTest(
                function () use ($testFile, &$state) {
                    $state['operationId'] = EventLoopFactory::getInstance()->addFileOperation(
                        'write',
                        $testFile,
                        'small data',
                        function () use (&$state) {
                            $state['completed'] = true;

                            EventLoopFactory::getInstance()->nextTick(function () use (&$state) {
                                $state['cancelResult'] = EventLoopFactory::getInstance()->cancelFileOperation(
                                    $state['operationId']
                                );
                            });
                        }
                    );
                },
                function () use (&$state) {
                    expect($state['completed'])->toBeTrue('Operation should complete');
                    expect($state['cancelResult'])->toBeFalse('Cannot cancel completed operation');
                },
                0.1
            );
        } finally {
            teardownCancellationTest($testFiles);
        }
    });

    it('demonstrates timing-dependent cancellation behavior', function () {
        setupCancellationTest();
        $testFiles = [];

        try {
            $testFile = __DIR__ . '/../temp/test_timing_demo.txt';
            $testFiles[] = $testFile;

            $smallData = str_repeat('X', 100);
            $state = captureOperationResult();

            $operationId = EventLoopFactory::getInstance()->addFileOperation(
                'write',
                $testFile,
                $smallData,
                function ($error, $result) use (&$state) {
                    $state['callbackExecuted'] = true;
                },
                ['use_streaming' => true]
            );

            EventLoopFactory::getInstance()->nextTick(function () use ($operationId, &$state) {
                $state['cancelled'] = EventLoopFactory::getInstance()->cancelFileOperation($operationId);
            });

            runAsyncTest(
                function () {},
                function () use (&$state) {
                    if ($state['cancelled']) {
                        expect($state['callbackExecuted'])->toBeFalse('Cancelled operation should not execute callback');
                    } else {
                        expect($state['callbackExecuted'])->toBeTrue('Completed operation should execute callback');
                    }
                },
                0.1
            );
        } finally {
            teardownCancellationTest($testFiles);
        }
    });

});
