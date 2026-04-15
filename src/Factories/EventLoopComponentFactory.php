<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Factories;

use Hibla\EventLoop\Drivers\StreamSelect\Handlers\SleepHandler as StreamSelectSleepHandler;
use Hibla\EventLoop\Drivers\StreamSelect\Handlers\WorkHandler as StreamSelectWorkHandler;
use Hibla\EventLoop\Drivers\StreamSelect\Managers\SignalManager as StreamSelectSignalManager;
use Hibla\EventLoop\Drivers\StreamSelect\Managers\StreamManager as StreamSelectStreamManager;
use Hibla\EventLoop\Drivers\StreamSelect\Managers\TimerManager as StreamSelectTimerManager;
use Hibla\EventLoop\Drivers\Uv\Handlers\SleepHandler as UvSleepHandler;
use Hibla\EventLoop\Drivers\Uv\Handlers\WorkHandler as UvWorkHandler;
use Hibla\EventLoop\Drivers\Uv\Managers\SignalManager as UvSignalManager;
use Hibla\EventLoop\Drivers\Uv\Managers\StreamManager as UvStreamManager;

use Hibla\EventLoop\Drivers\Uv\Managers\TimerManager as UvTimerManager;
use Hibla\EventLoop\Handlers\TickHandler;
use Hibla\EventLoop\Interfaces\CurlRequestManagerInterface;
use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\Interfaces\SignalManagerInterface;

use Hibla\EventLoop\Interfaces\SleepHandlerInterface;
use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\Interfaces\TimerManagerInterface;
use Hibla\EventLoop\Interfaces\UvTimerManagerInterface;
use Hibla\EventLoop\Interfaces\WorkHandlerInterface;

use RuntimeException;

final class EventLoopComponentFactory
{
    public const string DRIVER_UV = 'uv';
    public const string DRIVER_STREAM_SELECT = 'stream_select';
    public const string ENV_KEY = 'HIBLA_LOOP_DRIVER';

    /**
     * Detects available extensions and creates the underlying loop resource.
     *
     * Resolution order:
     *   1. HIBLA_LOOP_DRIVER environment variable (explicit override)
     *   2. Auto-detect — uv if ext-uv is loaded, stream_select otherwise
     *
     * @return \UVLoop|null Returns a UVLoop if the uv driver is selected, null for stream_select.
     * @throws RuntimeException If HIBLA_LOOP_DRIVER is set to an unsupported or unavailable driver.
     */
    public static function createLoopResource(): ?\UVLoop
    {
        $driver = self::resolveDriver();

        if ($driver === self::DRIVER_UV) {
            if (! extension_loaded('uv')) {
                throw new RuntimeException(
                    'HIBLA_LOOP_DRIVER is set to "uv" but the uv extension is not loaded.'
                );
            }

            // use default uv loop instead of creating a new one via uv_loop_new() as it causes seg fault when the loop is reset and forcibly stop and gc
            return \uv_default_loop();
        }

        return null;
    }

    public static function resolveDriver(): string
    {
        $env = $_SERVER[self::ENV_KEY] ?? $_ENV[self::ENV_KEY] ?? null;

        if ($env !== null && $env !== '') {
            assert(\is_string($env));
            $env = \strtolower(\trim($env));

            if (! \in_array($env, [self::DRIVER_UV, self::DRIVER_STREAM_SELECT], true)) {
                throw new RuntimeException(
                    sprintf(
                        'Unsupported value "%s" for %s. Supported drivers: uv, stream_select.',
                        $env,
                        self::ENV_KEY,
                    )
                );
            }

            return $env;
        }

        return extension_loaded('uv')
            ? self::DRIVER_UV
            : self::DRIVER_STREAM_SELECT;
    }

    public static function createWorkHandler(
        ?\UVLoop $loopResource,
        TimerManagerInterface $timerManager,
        CurlRequestManagerInterface $curlRequestManager,
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
                curlRequestManager: $curlRequestManager,
                streamManager: $streamManager,
                fiberManager: $fiberManager,
                tickHandler: $tickHandler,
                signalManager: $signalManager
            );
        }

        return new StreamSelectWorkHandler(
            timerManager: $timerManager,
            curlRequestManager: $curlRequestManager,
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
        CurlRequestManagerInterface $curlRequestManager,
        StreamManagerInterface $streamManager,
    ): SleepHandlerInterface {
        if ($loopResource !== null) {
            return new UvSleepHandler();
        }

        return new StreamSelectSleepHandler(
            $timerManager,
            $fiberManager,
            $curlRequestManager,
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
