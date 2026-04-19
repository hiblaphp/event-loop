<?php

declare(strict_types=1);

use Hibla\EventLoop\Drivers\StreamSelect\Handlers\SleepHandler;
use Hibla\EventLoop\Interfaces\CurlRequestManagerInterface;
use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\Interfaces\TimerManagerInterface;

function makeStreamSelectSleepHandler(
    ?TimerManagerInterface $timerManager = null,
    ?FiberManagerInterface $fiberManager = null,
    ?CurlRequestManagerInterface $curlRequestManager = null,
    ?StreamManagerInterface $streamManager = null,
): SleepHandler {
    return new SleepHandler(
        $timerManager ?? Mockery::mock(TimerManagerInterface::class),
        $fiberManager ?? Mockery::mock(FiberManagerInterface::class),
        $curlRequestManager ?? Mockery::mock(CurlRequestManagerInterface::class),
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

    it('returns false when there are active stream watchers', function () {
        $timerManager = Mockery::mock(TimerManagerInterface::class);
        $timerManager->allows('hasReadyTimers')->andReturn(false);

        $curlRequestManager = Mockery::mock(CurlRequestManagerInterface::class);
        $curlRequestManager->allows('hasRequests')->andReturn(false);

        $streamManager = Mockery::mock(StreamManagerInterface::class);
        $streamManager->allows('hasWatchers')->andReturn(true);

        $handler = makeStreamSelectSleepHandler(
            timerManager: $timerManager,
            curlRequestManager: $curlRequestManager,
            streamManager: $streamManager,
        );

        expect($handler->shouldSleep(false))->toBeFalse();
    });

    it('returns false when there are active HTTP requests', function () {
        $timerManager = Mockery::mock(TimerManagerInterface::class);
        $timerManager->allows('hasReadyTimers')->andReturn(false);

        $curlRequestManager = Mockery::mock(CurlRequestManagerInterface::class);
        $curlRequestManager->allows('hasRequests')->andReturn(true);

        $streamManager = Mockery::mock(StreamManagerInterface::class);
        $streamManager->allows('hasWatchers')->andReturn(false);

        $handler = makeStreamSelectSleepHandler(
            timerManager: $timerManager,
            curlRequestManager: $curlRequestManager,
            streamManager: $streamManager,
        );

        expect($handler->shouldSleep(false))->toBeFalse();
    });

    it('returns false when there are active fibers', function () {
        $timerManager = Mockery::mock(TimerManagerInterface::class);
        $timerManager->allows('hasReadyTimers')->andReturn(false);

        $curlRequestManager = Mockery::mock(CurlRequestManagerInterface::class);
        $curlRequestManager->allows('hasRequests')->andReturn(false);

        $streamManager = Mockery::mock(StreamManagerInterface::class);
        $streamManager->allows('hasWatchers')->andReturn(false);

        $fiberManager = Mockery::mock(FiberManagerInterface::class);
        $fiberManager->allows('hasActiveFibers')->andReturn(true);

        $handler = makeStreamSelectSleepHandler(
            timerManager: $timerManager,
            fiberManager: $fiberManager,
            curlRequestManager: $curlRequestManager,
            streamManager: $streamManager,
        );

        expect($handler->shouldSleep(false))->toBeFalse();
    });

    it('returns true when there is no immediate work and no pending I/O or fibers', function () {
        $timerManager = Mockery::mock(TimerManagerInterface::class);
        $timerManager->allows('hasReadyTimers')->andReturn(false);

        $curlRequestManager = Mockery::mock(CurlRequestManagerInterface::class);
        $curlRequestManager->allows('hasRequests')->andReturn(false);

        $streamManager = Mockery::mock(StreamManagerInterface::class);
        $streamManager->allows('hasWatchers')->andReturn(false);

        $fiberManager = Mockery::mock(FiberManagerInterface::class);
        $fiberManager->allows('hasActiveFibers')->andReturn(false);

        $handler = makeStreamSelectSleepHandler(
            timerManager: $timerManager,
            fiberManager: $fiberManager,
            curlRequestManager: $curlRequestManager,
            streamManager: $streamManager,
        );

        expect($handler->shouldSleep(false))->toBeTrue();
    });
});

describe('SleepHandler::calculateOptimalSleep', function () {
    it('returns 1 second fallback when there are no pending timers', function () {
        $timerManager = Mockery::mock(TimerManagerInterface::class);
        $timerManager->allows('getNextTimerDelay')->andReturn(null);

        $handler = makeStreamSelectSleepHandler(timerManager: $timerManager);
        $sleep = $handler->calculateOptimalSleep();

        expect($sleep)->toBe(1_000_000_000);
    });

    it('returns the exact next timer delay in nanoseconds', function () {
        $timerManager = Mockery::mock(TimerManagerInterface::class);
        $timerManager->allows('getNextTimerDelay')->andReturn(0.5); // 500ms

        $handler = makeStreamSelectSleepHandler(timerManager: $timerManager);
        $sleep = $handler->calculateOptimalSleep();

        expect($sleep)->toBe(500_000_000);
    });

    it('clamps sleep to the minimum of 100_000 nanoseconds', function () {
        $timerManager = Mockery::mock(TimerManagerInterface::class);
        $timerManager->allows('getNextTimerDelay')->andReturn(0.00001); // 10 microseconds

        $handler = makeStreamSelectSleepHandler(timerManager: $timerManager);
        $sleep = $handler->calculateOptimalSleep();

        expect($sleep)->toBe(100_000);
    });

    it('allows long sleeps without platform caps', function () {
        $timerManager = Mockery::mock(TimerManagerInterface::class);
        $timerManager->allows('getNextTimerDelay')->andReturn(10.0); // 10 seconds

        $handler = makeStreamSelectSleepHandler(timerManager: $timerManager);
        $sleep = $handler->calculateOptimalSleep();

        expect($sleep)->toBe(10_000_000_000);
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
});
