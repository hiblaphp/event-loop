<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Factories;

use Hibla\EventLoop\Handlers\SleepHandler;
use Hibla\EventLoop\Handlers\TickHandler;
use Hibla\EventLoop\Handlers\WorkHandler;
use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\Interfaces\FileWatcherManagerInterface;
use Hibla\EventLoop\Interfaces\HttpRequestManagerInterface;
use Hibla\EventLoop\Interfaces\SignalManagerInterface;
use Hibla\EventLoop\Interfaces\SleepHandlerInterface;
use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\Interfaces\TimerManagerInterface;
use Hibla\EventLoop\Interfaces\WorkHandlerInterface;
use Hibla\EventLoop\Managers\FileWatcherManager;
use Hibla\EventLoop\Managers\StreamManager;
use Hibla\EventLoop\Managers\TimerManager;

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
        return new WorkHandler(
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
        return new SleepHandler(
            $timerManager,
            $fiberManager,
            $httpRequestManager,
            $streamManager,
            $fileWatcherManager
        );
    }

    public static function createTimerManager(): TimerManagerInterface
    {
        return new TimerManager();
    }

    public static function createStreamManager(): StreamManagerInterface
    {
        return new StreamManager();
    }

    public static function createFileWatcherManager(): FileWatcherManagerInterface
    {
        return new FileWatcherManager();
    }
}
