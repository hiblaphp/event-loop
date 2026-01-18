<?php

declare(strict_types=1);

namespace Hibla\EventLoop;

use Fiber;
use Hibla\EventLoop\Factories\EventLoopComponentFactory;
use Hibla\EventLoop\Handlers\ActivityHandler;
use Hibla\EventLoop\Handlers\StateHandler;
use Hibla\EventLoop\Handlers\TickHandler;
use Hibla\EventLoop\Interfaces\FiberManagerInterface;
use Hibla\EventLoop\Interfaces\FileManagerInterface;
use Hibla\EventLoop\Interfaces\HttpRequestManagerInterface;
use Hibla\EventLoop\Interfaces\LoopInterface;
use Hibla\EventLoop\Interfaces\SignalManagerInterface;
use Hibla\EventLoop\Interfaces\SleepHandlerInterface;
use Hibla\EventLoop\Interfaces\StreamManagerInterface;
use Hibla\EventLoop\Interfaces\TimerManagerInterface;
use Hibla\EventLoop\Interfaces\WorkHandlerInterface;
use Hibla\EventLoop\Managers\FiberManager;
use Hibla\EventLoop\Managers\HttpRequestManager;
use Hibla\EventLoop\Managers\SignalManager;
use Hibla\EventLoop\ValueObjects\StreamWatcher;

final class EventLoopFactory implements LoopInterface
{
    private static ?EventLoopFactory $instance = null;

    private TimerManagerInterface $timerManager;

    private HttpRequestManagerInterface $httpRequestManager;

    private StreamManagerInterface $streamManager;

    private FiberManagerInterface $fiberManager;

    private TickHandler $tickHandler;

    private WorkHandlerInterface $workHandler;

    private SleepHandlerInterface $sleepHandler;

    private ActivityHandler $activityHandler;

    private StateHandler $stateHandler;

    private FileManagerInterface $fileManager;

    private SignalManagerInterface $signalManager;

    private bool $hasStarted = false;
    private static bool $autoRunRegistered = false;
    private static bool $explicitlyStopped = false;

    private function __construct()
    {
        $this->timerManager = EventLoopComponentFactory::createTimerManager();
        $this->fileManager = EventLoopComponentFactory::createFileManager();
        $this->streamManager = EventLoopComponentFactory::createStreamManager();
        $this->httpRequestManager = new HttpRequestManager();
        $this->fiberManager = new FiberManager();
        $this->tickHandler = new TickHandler();
        $this->activityHandler = new ActivityHandler();
        $this->stateHandler = new StateHandler();
        $this->signalManager = new SignalManager();

        $this->workHandler = EventLoopComponentFactory::createWorkHandler(
            timerManager: $this->timerManager,
            httpRequestManager: $this->httpRequestManager,
            streamManager: $this->streamManager,
            fiberManager: $this->fiberManager,
            tickHandler: $this->tickHandler,
            fileManager: $this->fileManager,
            signalManager: $this->signalManager,
        );

        $this->sleepHandler = EventLoopComponentFactory::createSleepHandler(
            timerManager: $this->timerManager,
            fiberManager: $this->fiberManager,
            httpRequestManager: $this->httpRequestManager,
            streamManager: $this->streamManager,
            fileManager: $this->fileManager,
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
    public function addHttpRequest(string $url, array $options, callable $callback): string
    {
        return $this->httpRequestManager->addHttpRequest($url, $options, $callback);
    }

    /**
     * @inheritDoc
     */
    public function cancelHttpRequest(string $requestId): bool
    {
        return $this->httpRequestManager->cancelHttpRequest($requestId);
    }

    /**
     * @inheritDoc
     */
    public function addStreamWatcher($stream, callable $callback, string $type = StreamWatcher::TYPE_READ): string
    {
        return $this->streamManager->addStreamWatcher($stream, $callback, $type);
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
    public function addReadWatcher($stream, callable $callback): string
    {
        return $this->streamManager->addReadWatcher($stream, $callback);
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
        $hasImmediateWork = $this->tick();

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

        if (!$this->stateHandler->isRunning() && $this->workHandler->hasWork()) {
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
     * @inheritDoc
     */
    public function addFileOperation(
        string $type,
        string $path,
        mixed $data,
        callable $callback,
        array $options = []
    ): string {
        return $this->fileManager->addFileOperation($type, $path, $data, $callback, $options);
    }

    /**
     * @inheritDoc
     */
    public function cancelFileOperation(string $operationId): bool
    {
        return $this->fileManager->cancelFileOperation($operationId);
    }

    /**
     * @inheritDoc
     */
    public function addFileWatcher(string $path, callable $callback, array $options = []): string
    {
        return $this->fileManager->addFileWatcher($path, $callback, $options);
    }

    /**
     * @inheritDoc
     */
    public function removeFileWatcher(string $watcherId): bool
    {
        return $this->fileManager->removeFileWatcher($watcherId);
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

            $this->tick();
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
        $this->httpRequestManager->clearAllRequests();
        $this->fileManager->clearAllOperations();
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
     * @return bool True if work was processed, false if no work was available
     */
    private function tick(): bool
    {
        $workDone = $this->workHandler->processWork();

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
