<?php

declare(strict_types=1);

use Hibla\EventLoop\Handlers\StateHandler;

describe('StateHandler', function () {
    it('initializes in running state', function () {
        $handler = new StateHandler();
        expect($handler->isRunning())->toBeTrue();
    });

    it('can stop gracefully', function () {
        $handler = new StateHandler();
        $handler->stop();

        expect($handler->isRunning())->toBeFalse();
        expect($handler->isForcedShutdown())->toBeFalse();
        expect($handler->isInGracefulShutdown())->toBeTrue();
    });

    it('can force stop', function () {
        $handler = new StateHandler();
        $handler->forceStop();

        expect($handler->isRunning())->toBeFalse();
        expect($handler->isForcedShutdown())->toBeTrue();
    });

    it('tracks stop request time', function () {
        $handler = new StateHandler();
        $beforeStop = microtime(true);

        $handler->stop();

        expect($handler->getTimeSinceStopRequest())->toBeGreaterThanOrEqual(0);
        expect($handler->getTimeSinceStopRequest())->toBeLessThan(1.0);
    });

    it('handles graceful shutdown timeout', function () {
        $handler = new StateHandler();
        $handler->setGracefulShutdownTimeout(0.1);

        expect($handler->getGracefulShutdownTimeout())->toBe(0.1);

        $handler->stop();
        expect($handler->shouldForceShutdown())->toBeFalse();

        usleep(150000);
        expect($handler->shouldForceShutdown())->toBeTrue();
    });

    it('can restart after stop', function () {
        $handler = new StateHandler();
        $handler->stop();
        expect($handler->isRunning())->toBeFalse();

        $handler->start();
        expect($handler->isRunning())->toBeTrue();
        expect($handler->isForcedShutdown())->toBeFalse();
        expect($handler->getTimeSinceStopRequest())->toBe(0.0);
    });

    it('enforces minimum timeout', function () {
        $handler = new StateHandler();
        $handler->setGracefulShutdownTimeout(0.05);

        expect($handler->getGracefulShutdownTimeout())->toBe(0.1);
    });
});
