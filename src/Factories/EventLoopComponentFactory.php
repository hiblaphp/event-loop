<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Factories;

use Hibla\EventLoop\Handlers\SleepHandler;
use Hibla\EventLoop\Handlers\TickHandler;
use Hibla\EventLoop\Handlers\WorkHandler;
use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\Interfaces\FileManagerInterface;
use Hibla\EventLoop\Interfaces\HttpRequestManagerInterface;
use Hibla\EventLoop\Interfaces\SignalManagerInterface;
use Hibla\EventLoop\Interfaces\SleepHandlerInterface;
use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\Interfaces\TimerManagerInterface;
use Hibla\EventLoop\Interfaces\WorkHandlerInterface;
use Hibla\EventLoop\Managers\FileManager;
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
        FileManagerInterface $fileManager,
        SignalManagerInterface $signalManager,
    ): WorkHandlerInterface {

        return new WorkHandler(
            $timerManager,
            $httpRequestManager,
            $streamManager,
            $fiberManager,
            $tickHandler,
            $fileManager,
            $signalManager,
        );
    }

    public static function createSleepHandler(
        TimerManagerInterface $timerManager,
        FiberManagerInterface $fiberManager
    ): SleepHandlerInterface {
        return new SleepHandler($timerManager, $fiberManager);
    }

    public static function createTimerManager(): TimerManagerInterface
    {
        return new TimerManager();
    }

    public static function createStreamManager(): StreamManagerInterface
    {
        return new StreamManager();
    }

    public static function createFileManager(): FileManagerInterface
    {
        return new FileManager();
    }
}
