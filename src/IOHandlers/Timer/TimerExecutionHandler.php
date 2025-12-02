<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Timer;

use Hibla\EventLoop\ValueObjects\Timer;
use Throwable;

final readonly class TimerExecutionHandler
{
    /**
     * @param  array<string, Timer>  &$timers 
     * @param  float  $currentTime 
     * @return bool True if at least one timer was executed, false otherwise.
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

    public function executeTimer(Timer $timer): void
    {
        try {
            $timer->execute();
        } catch (Throwable $e) {
            error_log('Timer callback error for timer '.$timer->getId().': '.$e->getMessage());
        }
    }
}
