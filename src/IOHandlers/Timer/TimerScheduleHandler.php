<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Timer;

use Hibla\EventLoop\ValueObjects\Timer;

final readonly class TimerScheduleHandler
{
    /**
     * @param  float  $delay
     * @param  callable  $callback
     * @return Timer
     */
    public function createTimer(float $delay, callable $callback): Timer
    {
        return new Timer($delay, $callback);
    }

    /**
     * @param  Timer[]  $timers
     * @return float|null
     */
    public function getNextExecutionTime(array $timers): ?float
    {
        if (\count($timers) === 0) {
            return null;
        }

        $nextExecuteTime = PHP_FLOAT_MAX;

        foreach ($timers as $timer) {
            $nextExecuteTime = min($nextExecuteTime, $timer->getExecuteAt());
        }

        return $nextExecuteTime;
    }

    /**
     * @param  Timer[]  $timers
     * @param  float  $currentTime
     * @return float|null
     */
    public function calculateDelay(array $timers, float $currentTime): ?float
    {
        $nextExecuteTime = $this->getNextExecutionTime($timers);

        if ($nextExecuteTime === null) {
            return null;
        }

        $delay = $nextExecuteTime - $currentTime;

        return $delay > 0 ? $delay : 0;
    }
}
