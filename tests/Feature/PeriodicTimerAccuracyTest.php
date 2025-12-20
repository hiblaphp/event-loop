<?php

use Hibla\EventLoop\Loop;

beforeEach(function () {
    Loop::reset();
});

test('periodic timer accuracy stays above 99% with sustained execution', function () {
    $targetInterval = 0.1; 
    $minIterations = 20;
    $timestamps = [];
    $timerId = null;

    $timerId = Loop::addPeriodicTimer($targetInterval, function () use (&$timestamps, $minIterations, &$timerId) {
        $timestamps[] = hrtime(true);

        if (count($timestamps) >= $minIterations) {
            if ($timerId) {
                Loop::cancelTimer($timerId);
            }
            Loop::stop();
        }
    });

    Loop::addTimer($targetInterval * $minIterations + 2.0, function () {
        Loop::stop();
    });

    Loop::run();
    expect($timestamps)
        ->toBeArray()
        ->and(count($timestamps))->toBeGreaterThanOrEqual($minIterations, 'Did not capture enough samples for accuracy check');

    $intervals = [];
    for ($i = 1; $i < count($timestamps); $i++) {
        $diffSecs = ($timestamps[$i] - $timestamps[$i - 1]) / 1_000_000_000;
        $intervals[] = $diffSecs;
    }

    $avgInterval = array_sum($intervals) / count($intervals);
    $deviation = abs($avgInterval - $targetInterval);
    $errorPercentage = $deviation / $targetInterval;
    $accuracy = (1.0 - $errorPercentage) * 100;

    $message = sprintf(
        "Accuracy fell below 99%%\nTarget: %.6fs\nActual: %.6fs\nAccuracy: %.4f%%\nSamples: %d", 
        $targetInterval, 
        $avgInterval, 
        $accuracy,
        count($timestamps)
    );

    expect($accuracy)
        ->toBeGreaterThanOrEqual(99.0, $message);
});