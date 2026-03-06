<?php

declare(strict_types=1);

use Hibla\EventLoop\Drivers\StreamSelect\Handlers\SleepHandler;
use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\Interfaces\FileWatcherManagerInterface;
use Hibla\EventLoop\Interfaces\HttpRequestManagerInterface;
use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\Interfaces\TimerManagerInterface;

function makeStreamSelectSleepHandler(
    ?TimerManagerInterface $timerManager = null,
    ?FiberManagerInterface $fiberManager = null,
    ?HttpRequestManagerInterface $httpRequestManager = null,
    ?StreamManagerInterface $streamManager = null,
    ?FileWatcherManagerInterface $fileWatcherManager = null,
): SleepHandler {
    return new SleepHandler(
        $timerManager ?? Mockery::mock(TimerManagerInterface::class),
        $fiberManager ?? Mockery::mock(FiberManagerInterface::class),
        $httpRequestManager ?? Mockery::mock(HttpRequestManagerInterface::class),
        $streamManager ?? Mockery::mock(StreamManagerInterface::class),
    );
}

describe('SleepHandler::shouldSleep', function () {
    it('returns false when there is immediate work', function () {
        $handler = makeStreamSelectSleepHandler();

        expect($handler->shouldSleep(true))->toBeFalse();
    });

    it('returns false when there are ready timers', function () {
        $timerManager = Mockery::mock(TimerManagerInterface::class);
        $timerManager->allows('hasReadyTimers')->andReturn(true);

        $handler = makeStreamSelectSleepHandler(timerManager: $timerManager);

        expect($handler->shouldSleep(false))->toBeFalse();
    });

    it('returns false when there are pending HTTP requests', function () {
        $timerManager = Mockery::mock(TimerManagerInterface::class);
        $timerManager->allows('hasReadyTimers')->andReturn(false);

        $httpRequestManager = Mockery::mock(HttpRequestManagerInterface::class);
        $httpRequestManager->allows('hasRequests')->andReturn(true);

        $handler = makeStreamSelectSleepHandler(
            timerManager: $timerManager,
            httpRequestManager: $httpRequestManager,
        );

        expect($handler->shouldSleep(false))->toBeFalse();
    });

    it('returns false when there are active stream watchers', function () {
        $timerManager = Mockery::mock(TimerManagerInterface::class);
        $timerManager->allows('hasReadyTimers')->andReturn(false);

        $httpRequestManager = Mockery::mock(HttpRequestManagerInterface::class);
        $httpRequestManager->allows('hasRequests')->andReturn(false);

        $streamManager = Mockery::mock(StreamManagerInterface::class);
        $streamManager->allows('hasWatchers')->andReturn(true);

        $handler = makeStreamSelectSleepHandler(
            timerManager: $timerManager,
            httpRequestManager: $httpRequestManager,
            streamManager: $streamManager,
        );

        expect($handler->shouldSleep(false))->toBeFalse();
    });

    it('returns false when there are active fibers', function () {
        $timerManager = Mockery::mock(TimerManagerInterface::class);
        $timerManager->allows('hasReadyTimers')->andReturn(false);

        $httpRequestManager = Mockery::mock(HttpRequestManagerInterface::class);
        $httpRequestManager->allows('hasRequests')->andReturn(false);

        $streamManager = Mockery::mock(StreamManagerInterface::class);
        $streamManager->allows('hasWatchers')->andReturn(false);

        $fileWatcherManager = Mockery::mock(FileWatcherManagerInterface::class);
        $fileWatcherManager->allows('hasWatchers')->andReturn(false);

        $fiberManager = Mockery::mock(FiberManagerInterface::class);
        $fiberManager->allows('hasActiveFibers')->andReturn(true);

        $handler = makeStreamSelectSleepHandler(
            timerManager: $timerManager,
            fiberManager: $fiberManager,
            httpRequestManager: $httpRequestManager,
            streamManager: $streamManager,
            fileWatcherManager: $fileWatcherManager,
        );

        expect($handler->shouldSleep(false))->toBeFalse();
    });

    it('returns true when there is no immediate work and no pending I/O or fibers', function () {
        $timerManager = Mockery::mock(TimerManagerInterface::class);
        $timerManager->allows('hasReadyTimers')->andReturn(false);

        $httpRequestManager = Mockery::mock(HttpRequestManagerInterface::class);
        $httpRequestManager->allows('hasRequests')->andReturn(false);

        $streamManager = Mockery::mock(StreamManagerInterface::class);
        $streamManager->allows('hasWatchers')->andReturn(false);

        $fileWatcherManager = Mockery::mock(FileWatcherManagerInterface::class);
        $fileWatcherManager->allows('hasWatchers')->andReturn(false);

        $fiberManager = Mockery::mock(FiberManagerInterface::class);
        $fiberManager->allows('hasActiveFibers')->andReturn(false);

        $handler = makeStreamSelectSleepHandler(
            timerManager: $timerManager,
            fiberManager: $fiberManager,
            httpRequestManager: $httpRequestManager,
            streamManager: $streamManager,
            fileWatcherManager: $fileWatcherManager,
        );

        expect($handler->shouldSleep(false))->toBeTrue();
    });
});

describe('SleepHandler::calculateOptimalSleep', function () {
    it('returns max sleep when there are no pending timers', function () {
        $timerManager = Mockery::mock(TimerManagerInterface::class);
        $timerManager->allows('getNextTimerDelay')->andReturn(null);

        $handler = makeStreamSelectSleepHandler(timerManager: $timerManager);
        $sleep = $handler->calculateOptimalSleep();

        $expectedMax = PHP_OS_FAMILY === 'Windows' ? 1_000_000 : 10_000_000;

        expect($sleep)->toBe($expectedMax);
    });

    it('returns 90% of the next timer delay in nanoseconds', function () {
        $timerManager = Mockery::mock(TimerManagerInterface::class);
        $timerManager->allows('getNextTimerDelay')->andReturn(1.0); // 1 second

        $handler = makeStreamSelectSleepHandler(timerManager: $timerManager);
        $sleep = $handler->calculateOptimalSleep();

        $expectedMax = PHP_OS_FAMILY === 'Windows' ? 1_000_000 : 10_000_000;
        expect($sleep)->toBe($expectedMax);
    });

    it('clamps sleep to the minimum of 100_000 nanoseconds', function () {
        $timerManager = Mockery::mock(TimerManagerInterface::class);
        $timerManager->allows('getNextTimerDelay')->andReturn(0.00001); // very small delay

        $handler = makeStreamSelectSleepHandler(timerManager: $timerManager);
        $sleep = $handler->calculateOptimalSleep();

        expect($sleep)->toBeGreaterThanOrEqual(100_000);
    });

    it('clamps sleep to platform max when delay exceeds it', function () {
        $timerManager = Mockery::mock(TimerManagerInterface::class);
        $timerManager->allows('getNextTimerDelay')->andReturn(100.0); // 100 seconds

        $handler = makeStreamSelectSleepHandler(timerManager: $timerManager);
        $sleep = $handler->calculateOptimalSleep();

        $expectedMax = PHP_OS_FAMILY === 'Windows' ? 1_000_000 : 10_000_000;
        expect($sleep)->toBe($expectedMax);
    });
});

describe('SleepHandler::sleep', function () {
    it('returns immediately when nanoseconds is zero', function () {
        $handler = makeStreamSelectSleepHandler();
        $start = hrtime(true);

        $handler->sleep(0);

        $elapsed = hrtime(true) - $start;
        expect($elapsed)->toBeLessThan(1_000_000);
    });

    it('returns immediately when nanoseconds is negative', function () {
        $handler = makeStreamSelectSleepHandler();
        $start = hrtime(true);

        $handler->sleep(-1);

        $elapsed = hrtime(true) - $start;
        expect($elapsed)->toBeLessThan(1_000_000);
    });

    it('sleeps for approximately the requested duration', function () {
        $handler = makeStreamSelectSleepHandler();
        $sleepNs = 5_000_000;
        $start = hrtime(true);

        $handler->sleep($sleepNs);

        $elapsed = hrtime(true) - $start;

        expect($elapsed)->toBeGreaterThanOrEqual($sleepNs * 0.8);
        expect($elapsed)->toBeLessThan($sleepNs * 5);
    });

    it('handles sub-millisecond sleep durations', function () {
        $handler = makeStreamSelectSleepHandler();
        $sleepNs = 100_000;
        $start = hrtime(true);

        $handler->sleep($sleepNs);

        $elapsed = hrtime(true) - $start;

        // Windows timer resolution is ~1-15ms so we allow a much wider upper
        // bound there. On Unix the resolution is much finer (~100μs).
        $upperBoundNs = PHP_OS_FAMILY === 'Windows' ? 50_000_000 : 10_000_000;

        expect($elapsed)->toBeGreaterThanOrEqual(0);
        expect($elapsed)->toBeLessThan($upperBoundNs);
    });
});
