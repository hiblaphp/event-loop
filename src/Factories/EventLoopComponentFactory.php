<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Factories;

use Hibla\EventLoop\Handlers\TickHandler;
use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\Interfaces\HttpRequestManagerInterface;
use Hibla\EventLoop\Interfaces\SignalManagerInterface;
use Hibla\EventLoop\Interfaces\SleepHandlerInterface;
use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\Interfaces\TimerManagerInterface;
use Hibla\EventLoop\Interfaces\UvTimerManagerInterface;
use Hibla\EventLoop\Interfaces\WorkHandlerInterface;

use Hibla\EventLoop\Drivers\StreamSelect\Managers\SignalManager as StreamSelectSignalManager;
use Hibla\EventLoop\Drivers\StreamSelect\Managers\StreamManager as StreamSelectStreamManager;
use Hibla\EventLoop\Drivers\StreamSelect\Managers\TimerManager as StreamSelectTimerManager;
use Hibla\EventLoop\Drivers\StreamSelect\Handlers\SleepHandler as StreamSelectSleepHandler;
use Hibla\EventLoop\Drivers\StreamSelect\Handlers\WorkHandler as StreamSelectWorkHandler;

use Hibla\EventLoop\Drivers\Uv\Managers\SignalManager as UvSignalManager;
use Hibla\EventLoop\Drivers\Uv\Managers\StreamManager as UvStreamManager;
use Hibla\EventLoop\Drivers\Uv\Managers\TimerManager as UvTimerManager;
use Hibla\EventLoop\Drivers\Uv\Handlers\SleepHandler as UvSleepHandler;
use Hibla\EventLoop\Drivers\Uv\Handlers\WorkHandler as UvWorkHandler;

final class EventLoopComponentFactory
{
    /**
     * Detects available extensions and creates the underlying loop resource.
     *
     * @return \UVLoop|null Returns a uv_loop resource if ext-uv is available, null otherwise.
     */
    public static function createLoopResource(): ?\UVLoop
    {
        if (\function_exists('uv_loop_new')) {
            return \uv_loop_new();
        }

        return null;
    }

    public static function createWorkHandler(
        ?\UVLoop $loopResource,
        TimerManagerInterface $timerManager,
        HttpRequestManagerInterface $httpRequestManager,
        StreamManagerInterface $streamManager,
        FiberManagerInterface $fiberManager,
        TickHandler $tickHandler,
        SignalManagerInterface $signalManager,
    ): WorkHandlerInterface {
        if ($loopResource !== null) {
            assert($timerManager instanceof UvTimerManagerInterface);

            return new UvWorkHandler(
                uvLoop: $loopResource,
                timerManager: $timerManager,
                httpRequestManager: $httpRequestManager,
                streamManager: $streamManager,
                fiberManager: $fiberManager,
                tickHandler: $tickHandler,
                signalManager: $signalManager
            );
        }

        return new StreamSelectWorkHandler(
            timerManager: $timerManager,
            httpRequestManager: $httpRequestManager,
            streamManager: $streamManager,
            fiberManager: $fiberManager,
            tickHandler: $tickHandler,
            signalManager: $signalManager,
        );
    }

    public static function createSleepHandler(
        ?\UVLoop $loopResource,
        TimerManagerInterface $timerManager,
        FiberManagerInterface $fiberManager,
        HttpRequestManagerInterface $httpRequestManager,
        StreamManagerInterface $streamManager,
    ): SleepHandlerInterface {
        if ($loopResource !== null) {
            return new UvSleepHandler();
        }

        return new StreamSelectSleepHandler(
            $timerManager,
            $fiberManager,
            $httpRequestManager,
            $streamManager,
        );
    }

    public static function createTimerManager(?\UVLoop $loopResource): TimerManagerInterface
    {
        if ($loopResource !== null) {
            return new UvTimerManager($loopResource);
        }

        return new StreamSelectTimerManager();
    }

    public static function createStreamManager(?\UVLoop $loopResource): StreamManagerInterface
    {
        if ($loopResource !== null) {
            return new UvStreamManager($loopResource);
        }

        return new StreamSelectStreamManager();
    }

    public static function createSignalManager(?\UVLoop $loopResource): SignalManagerInterface
    {
        if ($loopResource !== null) {
            return new UvSignalManager($loopResource);
        }

        return new StreamSelectSignalManager();
    }
}