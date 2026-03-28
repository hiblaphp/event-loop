<?php

declare(strict_types=1);

namespace Hibla\EventLoop;

use Fiber;
use Hibla\EventLoop\Factories\EventLoopComponentFactory;
use Hibla\EventLoop\Handlers\ActivityHandler;
use Hibla\EventLoop\Handlers\StateHandler;
use Hibla\EventLoop\Handlers\TickHandler;
use Hibla\EventLoop\Interfaces\CurlRequestManagerInterface;
use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\Interfaces\LoopInterface;
use Hibla\EventLoop\Interfaces\SignalManagerInterface;
use Hibla\EventLoop\Interfaces\SleepHandlerInterface;
use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\Interfaces\TimerManagerInterface;
use Hibla\EventLoop\Interfaces\WorkHandlerInterface;
use Hibla\EventLoop\Managers\CurlRequestManager;
use Hibla\EventLoop\Managers\FiberManager;

final class EventLoopFactory implements LoopInterface
{
    private static ?EventLoopFactory $instance = null;

    private TimerManagerInterface $timerManager;

    private CurlRequestManagerInterface $curlRequestManager;

    private StreamManagerInterface $streamManager;

    private FiberManagerInterface $fiberManager;

    private TickHandler $tickHandler;

    private WorkHandlerInterface $workHandler;

    private SleepHandlerInterface $sleepHandler;

    private ActivityHandler $activityHandler;

    private StateHandler $stateHandler;

    private SignalManagerInterface $signalManager;

    /**
     * @var mixed The underlying loop resource (e.g., uv_loop), or null if native PHP.
     */
    private mixed $loopResource;

    private bool $hasStarted = false;
    private static bool $autoRunRegistered = false;
    private static bool $explicitlyStopped = false;

    private function __construct()
    {
        $this->loopResource = EventLoopComponentFactory::createLoopResource();

        $this->curlRequestManager = new CurlRequestManager();
        $this->fiberManager = new FiberManager();
        $this->tickHandler = new TickHandler();
        $this->activityHandler = new ActivityHandler();
        $this->stateHandler = new StateHandler();

        $this->timerManager = EventLoopComponentFactory::createTimerManager($this->loopResource);
        $this->streamManager = EventLoopComponentFactory::createStreamManager($this->loopResource);
        $this->signalManager = EventLoopComponentFactory::createSignalManager($this->loopResource);

        $this->workHandler = EventLoopComponentFactory::createWorkHandler(
            loopResource: $this->loopResource,
            timerManager: $this->timerManager,
            curlRequestManager: $this->curlRequestManager,
            streamManager: $this->streamManager,
            fiberManager: $this->fiberManager,
            tickHandler: $this->tickHandler,
            signalManager: $this->signalManager,
        );

        $this->sleepHandler = EventLoopComponentFactory::createSleepHandler(
            loopResource: $this->loopResource,
            timerManager: $this->timerManager,
            fiberManager: $this->fiberManager,
            curlRequestManager: $this->curlRequestManager,
            streamManager: $this->streamManager,
        );

        $this->registerAutoRun();
    }

    /**
     * Get the singleton instance of the event loop.
     *
     * Creates a new instance if one doesn't exist, otherwise returns
     * the existing instance to ensure only one event loop runs per process.
     *
     * @return EventLoopFactory The singleton event loop instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @inheritDoc
     */
    public function addSignal(int $signal, callable $callback): string
    {
        return $this->signalManager->addSignal($signal, $callback);
    }

    /**
     * @inheritDoc
     */
    public function removeSignal(string $signalId): bool
    {
        return $this->signalManager->removeSignal($signalId);
    }

    /**
     * @inheritDoc
     */
    public function addTimer(float $delay, callable $callback): string
    {
        return $this->timerManager->addTimer($delay, $callback);
    }

    /**
     * @inheritDoc
     */
    public function addPeriodicTimer(float $interval, callable $callback, ?int $maxExecutions = null): string
    {
        return $this->timerManager->addPeriodicTimer($interval, $callback, $maxExecutions);
    }

    public function hasTimers(): bool
    {
        return $this->timerManager->hasTimers();
    }

    /**
     * @inheritDoc
     */
    public function cancelTimer(string $timerId): bool
    {
        return $this->timerManager->cancelTimer($timerId);
    }

    /**
     * @inheritDoc
     */
    public function addCurlRequest(string $url, array $options, callable $callback): string
    {
        return $this->curlRequestManager->addCurlRequest($url, $options, $callback);
    }

    /**
     * @inheritDoc
     */
    public function cancelCurlRequest(string $requestId): bool
    {
        return $this->curlRequestManager->cancelCurlRequest($requestId);
    }

    /**
     * @inheritDoc
     */
    public function addReadWatcher($stream, callable $callback): string
    {
        return $this->streamManager->addReadWatcher($stream, $callback);
    }

    /**
     * @inheritDoc
     */
    public function removeStreamWatcher(string $watcherId): bool
    {
        return $this->streamManager->removeStreamWatcher($watcherId);
    }

    /**
     * @inheritDoc
     */
    public function addWriteWatcher($stream, callable $callback): string
    {
        return $this->streamManager->addWriteWatcher($stream, $callback);
    }

    /**
     * @inheritDoc
     */
    public function removeReadWatcher($stream): bool
    {
        return $this->streamManager->removeReadWatcher($stream);
    }

    /**
     * @inheritDoc
     */
    public function removeWriteWatcher($stream): bool
    {
        return $this->streamManager->removeWriteWatcher($stream);
    }

    /**
     * @inheritDoc
     */
    public function addFiber(Fiber $fiber): void
    {
        /** @var Fiber<null, mixed, mixed, mixed> $compatibleFiber */
        $compatibleFiber = $fiber;
        $this->fiberManager->addFiber($compatibleFiber);
    }

    /**
     * @inheritDoc
     */
    public function scheduleFiber(Fiber $fiber): void
    {
        /** @var Fiber<null, mixed, mixed, mixed> $compatibleFiber */
        $compatibleFiber = $fiber;
        $this->fiberManager->scheduleFiber($compatibleFiber);
    }

    /**
     * @inheritDoc
     */
    public function nextTick(callable $callback): void
    {
        $this->tickHandler->addNextTick($callback);
    }

    /**
     * @inheritDoc
     */
    public function microTask(callable $callback): void
    {
        $this->tickHandler->addMicrotask($callback);
    }

    /**
     * @inheritDoc
     */
    public function setImmediate(callable $callback): void
    {
        $this->tickHandler->addImmediate($callback);
    }

    /**
     * @inheritDoc
     */
    public function defer(callable $callback): void
    {
        $this->tickHandler->addDeferred($callback);
    }

    /**
     * @inheritDoc
     */
    public function runOnce(): void
    {
        $hasImmediateWork = $this->tick(true);

        if ($this->sleepHandler->shouldSleep($hasImmediateWork)) {
            $sleepTime = $this->sleepHandler->calculateOptimalSleep();
            $this->sleepHandler->sleep($sleepTime);
        }
    }

    /**
     * @inheritDoc
     */
    public function run(): void
    {
        while ($this->stateHandler->isRunning() && $this->workHandler->hasWork()) {
            $this->runOnce();
        }

        if (! $this->stateHandler->isRunning() && $this->workHandler->hasWork()) {
            $this->handleGracefulShutdown();
        }
    }

    /**
     * @inheritDoc
     */
    public function forceStop(): void
    {
        self::$explicitlyStopped = true;
        $this->forceShutdown();
    }

    /**
     * @inheritDoc
     */
    public function isRunning(): bool
    {
        return $this->stateHandler->isRunning();
    }

    /**
     * @inheritDoc
     */
    public function stop(): void
    {
        self::$explicitlyStopped = true;
        $this->stateHandler->stop();
    }

    /**
     * @inheritDoc
     */
    public function isIdle(): bool
    {
        return ! $this->workHandler->hasWork() || $this->activityHandler->isIdle();
    }

    /**
     * Handle graceful shutdown with fallback to forced shutdown.
     */
    private function handleGracefulShutdown(): void
    {
        $maxGracefulIterations = 10;
        $gracefulCount = 0;

        while (
            $this->workHandler->hasWork() &&
            $gracefulCount < $maxGracefulIterations &&
            ! $this->stateHandler->shouldForceShutdown()
        ) {

            $this->tick(false);
            $gracefulCount++;

            usleep(1000);
        }

        if ($this->workHandler->hasWork() || $this->stateHandler->shouldForceShutdown()) {
            $this->forceShutdown();
        }
    }

    /**
     * Force shutdown by clearing all pending work.
     * This prevents the loop from hanging when stop() is called.
     */
    private function forceShutdown(): void
    {
        $this->stateHandler->forceStop();
        $this->clearAllWork();
    }

    /**
     * Clear all pending work from all managers and handlers.
     * This is used during forced shutdown to ensure clean exit.
     */
    private function clearAllWork(): void
    {
        $this->tickHandler->clearAllCallbacks();
        $this->timerManager->clearAllTimers();
        $this->curlRequestManager->clearAllRequests();
        $this->streamManager->clearAllWatchers();
        $this->fiberManager->prepareForShutdown();
        $this->signalManager->clearAllSignals();
    }

    /**
     * Register shutdown function to auto-run the loop at script end.
     */
    private function registerAutoRun(): void
    {
        if (self::$autoRunRegistered) {
            return;
        }

        self::$autoRunRegistered = true;

        register_shutdown_function(function () {
            $error = error_get_last();
            if ((($error['type'] ?? 0) & (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR)) !== 0) {
                return;
            }

            if (! self::$explicitlyStopped && ! $this->hasStarted && $this->workHandler->hasWork()) {
                $this->run();
            }
        });
    }

    /**
     * Process one iteration of the event loop.
     *
     * Executes all available work and updates activity tracking.
     * This is the core processing method called by the main run loop.
     *
     * @param bool $blocking Whether the work handler is allowed to block for I/O
     * @return bool True if work was processed, false if no work was available
     */
    private function tick(bool $blocking = true): bool
    {
        $workDone = $this->workHandler->processWork($blocking);

        if ($workDone) {
            $this->activityHandler->updateLastActivity();
        }

        return $workDone;
    }

    /**
     * Resets the singleton instance. Primarily for testing purposes.
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$autoRunRegistered = false;
        self::$explicitlyStopped = false;
    }
}
