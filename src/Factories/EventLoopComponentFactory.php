<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Factories;

use Hibla\EventLoop\Handlers\TickHandler;
use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\Interfaces\FileWatcherManagerInterface;
use Hibla\EventLoop\Interfaces\HttpRequestManagerInterface;
use Hibla\EventLoop\Interfaces\SignalManagerInterface;
use Hibla\EventLoop\Interfaces\SleepHandlerInterface;
use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\Interfaces\TimerManagerInterface;
use Hibla\EventLoop\Interfaces\WorkHandlerInterface;

use Hibla\EventLoop\Drivers\StreamSelect\Managers\FileWatcherManager as StreamSelectFileWatcherManager;
use Hibla\EventLoop\Drivers\StreamSelect\Managers\SignalManager as StreamSelectSignalManager;
use Hibla\EventLoop\Drivers\StreamSelect\Managers\StreamManager as StreamSelectStreamManager;
use Hibla\EventLoop\Drivers\StreamSelect\Managers\TimerManager as StreamSelectTimerManager;
use Hibla\EventLoop\Drivers\StreamSelect\Handlers\SleepHandler as StreamSelectSleepHandler;
use Hibla\EventLoop\Drivers\StreamSelect\Handlers\WorkHandler as StreamSelectWorkHandler;

final class EventLoopComponentFactory
{
    public static function createWorkHandler(
        TimerManagerInterface $timerManager,
        HttpRequestManagerInterface $httpRequestManager,
        StreamManagerInterface $streamManager,
        FiberManagerInterface $fiberManager,
        TickHandler $tickHandler,
        FileWatcherManagerInterface $fileWatcherManager,
        SignalManagerInterface $signalManager,
    ): WorkHandlerInterface {
        return new StreamSelectWorkHandler(
            $timerManager,
            $httpRequestManager,
            $streamManager,
            $fiberManager,
            $tickHandler,
            $fileWatcherManager,
            $signalManager,
        );
    }

    public static function createSleepHandler(
        TimerManagerInterface $timerManager,
        FiberManagerInterface $fiberManager,
        HttpRequestManagerInterface $httpRequestManager,
        StreamManagerInterface $streamManager,
        FileWatcherManagerInterface $fileWatcherManager,
    ): SleepHandlerInterface {
        return new StreamSelectSleepHandler(
            $timerManager,
            $fiberManager,
            $httpRequestManager,
            $streamManager,
            $fileWatcherManager
        );
    }

    public static function createTimerManager(): TimerManagerInterface
    {
        return new StreamSelectTimerManager();
    }

    public static function createStreamManager(): StreamManagerInterface
    {
        return new StreamSelectStreamManager();
    }

    public static function createFileWatcherManager(): FileWatcherManagerInterface
    {
        return new StreamSelectFileWatcherManager();
    }

    public static function createSignalManager(): SignalManagerInterface
    {
        return new StreamSelectSignalManager();
    }
}
