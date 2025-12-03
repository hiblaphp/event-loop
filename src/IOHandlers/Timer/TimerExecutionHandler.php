<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Timer;

use Hibla\EventLoop\ValueObjects\Timer;
use Throwable;

final readonly class TimerExecutionHandler
{
    /**
     * Execute all ready timers from the provided array
     *
     * @param  array<string, Timer>  &$timers
     * @param  float  $currentTime
     * @return bool True if at least one timer was executed
     */
    public function executeReadyTimers(array &$timers, float $currentTime): bool
    {
        $processed = false;

        foreach ($timers as $timerId => $timer) {
            if ($timer->isReady($currentTime)) {
                $this->executeTimer($timer);
                unset($timers[$timerId]);
                $processed = true;
            }
        }

        return $processed;
    }

    /**
     * Get all timers that are ready for execution
     *
     * @param  array<string, Timer>  $timers
     * @param  float  $currentTime
     * @return array<string, Timer>
     */
    public function getReadyTimers(array $timers, float $currentTime): array
    {
        return array_filter(
            $timers,
            fn (Timer $timer): bool => $timer->isReady($currentTime)
        );
    }

    /**
     * Execute a single timer with error handling
     */
    public function executeTimer(Timer $timer): void
    {
        try {
            $timer->execute();
        } catch (Throwable $e) {
            error_log(sprintf(
                'Timer callback error for timer %s: %s in %s:%d',
                $timer->getId(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
        }
    }
}
