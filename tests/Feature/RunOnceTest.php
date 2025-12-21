<?php

use Hibla\EventLoop\Loop;

test('runOnce executes immediate tasks and returns immediately', function () {
    $executed = false;

    Loop::nextTick(function () use (&$executed) {
        $executed = true;
    });

    Loop::runOnce();

    expect($executed)->toBeTrue('Immediate task should have executed');
});

test('runOnce executes one cycle but leaves future work pending', function () {
    $tick1Executed = false;
    $tick2Executed = false;

    Loop::addTimer(0, function () use (&$tick1Executed) {
        $tick1Executed = true;
    });

    Loop::addTimer(0.2, function () use (&$tick2Executed) {
        $tick2Executed = true;
    });

    Loop::runOnce();

    expect($tick1Executed)->toBeTrue('Immediate timer should run in first tick')
        ->and($tick2Executed)->toBeFalse('Future timer should NOT run in first tick');

    usleep(250000); 

    Loop::runOnce();

    expect($tick2Executed)->toBeTrue('Future timer should run in second tick after delay');
});

test('runOnce advances time efficiently for future timers', function () {
    $executed = false;
    $delay = 0.05;

    $start = hrtime(true);

    Loop::addTimer($delay, function () use (&$executed) {
        $executed = true;
    });
    
    $iterations = 0;
    $maxIterations = 200; 

    while (!$executed && $iterations < $maxIterations) {
        Loop::runOnce();
        $iterations++;
    }

    $end = hrtime(true);
    $elapsedSecs = ($end - $start) / 1e9;

    expect($executed)->toBeTrue('Timer should execute within the manual pump loop');
    
    expect($elapsedSecs)->toBeGreaterThanOrEqual($delay * 0.9);

    expect($iterations)->toBeLessThan($maxIterations);
});

test('runOnce allows manual loop pumping for background tasks', function () {
    $counter = 0;

    Loop::addPeriodicTimer(0.01, function () use (&$counter) {
        $counter++;
    });

    for ($i = 0; $i < 5; $i++) {
        usleep(15000); 
        Loop::runOnce();
    }

    expect($counter)->toBeGreaterThanOrEqual(5);
    
    Loop::stop();
});