<?php

declare(strict_types=1);

use Hibla\EventLoop\EventLoopFactory;

describe('EventLoop Integration', function () {
    it('executes event loop lifecycle phases in correct order', function () {
        $output = [];

        // Test 1: Basic Microtask Draining
        EventLoopFactory::getInstance()->microTask(function () use (&$output) {
            $output[] = '1. Microtask 1';
            EventLoopFactory::getInstance()->microTask(function () use (&$output) {
                $output[] = '2. Microtask 2 (nested)';
                EventLoopFactory::getInstance()->microTask(function () use (&$output) {
                    $output[] = '3. Microtask 3 (deeply nested)';
                });
            });
        });

        EventLoopFactory::getInstance()->microTask(function () use (&$output) {
            $output[] = '4. Microtask 4 (sibling)';
        });

        EventLoopFactory::getInstance()->addTimer(0, function () use (&$output) {
            $output[] = '5. Timer';

            // Test 2: NextTick vs Microtask Priority
            EventLoopFactory::getInstance()->nextTick(function () use (&$output) {
                $output[] = '6. NextTick 1';
                EventLoopFactory::getInstance()->microTask(function () use (&$output) {
                    $output[] = '8. Microtask from NextTick';
                });
            });

            EventLoopFactory::getInstance()->microTask(function () use (&$output) {
                $output[] = '7. Microtask 1';
            });

            EventLoopFactory::getInstance()->addTimer(0, function () use (&$output) {
                $output[] = '9. Timer 2';

                // Test 3: Multiple Timers with Microtasks
                EventLoopFactory::getInstance()->addTimer(0, function () use (&$output) {
                    $output[] = '10. Timer A';
                    EventLoopFactory::getInstance()->microTask(function () use (&$output) {
                        $output[] = '11. Microtask after Timer A';
                    });
                });

                EventLoopFactory::getInstance()->addTimer(0, function () use (&$output) {
                    $output[] = '12. Timer B';
                    EventLoopFactory::getInstance()->microTask(function () use (&$output) {
                        $output[] = '13. Microtask after Timer B';

                        // Test 4: Set Immediate Callbacks
                        EventLoopFactory::getInstance()->setImmediate(function () use (&$output) {
                            $output[] = '15. Set Immidiate 1';
                            EventLoopFactory::getInstance()->microTask(function () use (&$output) {
                                $output[] = '16. Microtask from Set Immidiate';

                                // Test 5: Complex Nested Scenario
                                EventLoopFactory::getInstance()->nextTick(function () use (&$output) {
                                    $output[] = '17. NextTick in complex';

                                    EventLoopFactory::getInstance()->microTask(function () use (&$output) {
                                        $output[] = '18. Microtask in NextTick';

                                        EventLoopFactory::getInstance()->addTimer(0, function () use (&$output) {
                                            $output[] = '21. Timer in Microtask';

                                            EventLoopFactory::getInstance()->microTask(function () use (&$output) {
                                                $output[] = '22. Microtask in Timer';
                                            });
                                        });
                                    });

                                    EventLoopFactory::getInstance()->setImmediate(function () use (&$output) {
                                        $output[] = '23. Set Immidiate in NextTick';
                                    });
                                });

                                EventLoopFactory::getInstance()->microTask(function () use (&$output) {
                                    $output[] = '19. Top-level Microtask';
                                });

                                EventLoopFactory::getInstance()->addTimer(0, function () use (&$output) {
                                    $output[] = '20. Top-level Timer';
                                });
                            });
                        });

                        EventLoopFactory::getInstance()->microTask(function () use (&$output) {
                            $output[] = '14. Microtask before Set Immidiate';
                        });
                    });
                });

                EventLoopFactory::getInstance()->microTask(function () use (&$output) {
                    $output[] = '9b. Microtask after Timer 2';
                });
            });
        });

        $output[] = 'Start';

        EventLoopFactory::getInstance()->run();

        $expected = [
            'Start',
            '1. Microtask 1',
            '4. Microtask 4 (sibling)',
            '2. Microtask 2 (nested)',
            '3. Microtask 3 (deeply nested)',
            '5. Timer',
            '6. NextTick 1',
            '7. Microtask 1',
            '8. Microtask from NextTick',
            '9. Timer 2',
            '9b. Microtask after Timer 2',
            '10. Timer A',
            '11. Microtask after Timer A',
            '12. Timer B',
            '13. Microtask after Timer B',
            '14. Microtask before Set Immidiate',
            '15. Set Immidiate 1',
            '16. Microtask from Set Immidiate',
            '19. Top-level Microtask',
            '17. NextTick in complex',
            '18. Microtask in NextTick',
            '23. Set Immidiate in NextTick',
            '20. Top-level Timer',
            '21. Timer in Microtask',
            '22. Microtask in Timer',
        ];

        expect($output)->toBe($expected);
    });

    it('runs a complete event loop cycle', function () {
        $loop = EventLoopFactory::getInstance();
        $results = [];

        $loop->nextTick(function () use (&$results) {
            $results[] = 'nextTick';
        });

        $loop->addTimer(0.001, function () use (&$results) {
            $results[] = 'timer';
        });

        $loop->defer(function () use (&$results) {
            $results[] = 'deferred';
        });

        $loop->run();

        expect($results)->toContain('nextTick');
        expect($results)->toContain('timer');
        expect($results)->toContain('deferred');

        expect(array_search('nextTick', $results))->toBeLessThan(array_search('deferred', $results));
    });

    it('handles multiple timers correctly', function () {
        $loop = EventLoopFactory::getInstance();
        $results = [];

        $loop->addTimer(0.001, function () use (&$results) {
            $results[] = 'timer1';
        });

        $loop->addTimer(0.002, function () use (&$results) {
            $results[] = 'timer2';
        });

        $loop->addTimer(0.003, function () use (&$results, $loop) {
            $results[] = 'timer3';
            $loop->stop();
        });

        $loop->run();

        expect($results)->toBe(['timer1', 'timer2', 'timer3']);
    });

    it('handles periodic timers', function () {
        $loop = EventLoopFactory::getInstance();
        $count = 0;

        $loop->addPeriodicTimer(0.001, function () use (&$count) {
            $count++;
        }, 5); 

        $loop->addTimer(0.01, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        expect($count)->toBe(5);
    });

    it('can cancel timers', function () {
        $loop = EventLoopFactory::getInstance();
        $executed = false;

        $timerId = $loop->addTimer(0.001, function () use (&$executed) {
            $executed = true;
        });

        $cancelled = $loop->cancelTimer($timerId);
        expect($cancelled)->toBeTrue();

        runLoopFor(0.01);

        expect($executed)->toBeFalse();
    });

    it('processes stream watchers', function () {
        $loop = EventLoopFactory::getInstance();
        $stream = createTestStream();
        $read = false;

        fwrite($stream, 'test data');
        rewind($stream);

        $watcherId = $loop->addStreamWatcher($stream, function () use (&$read) {
            $read = true;
        });

        runLoopFor(0.01);

        expect($read)->toBeTrue();

        $loop->removeStreamWatcher($watcherId);
        fclose($stream);
    });

    it('handles fiber execution', function () {
        $loop = EventLoopFactory::getInstance();

        $fiber = new Fiber(function () {
            $value = Fiber::suspend('waiting');

            return "completed with $value";
        });

        $loop->addFiber($fiber);

        $loop->addTimer(0.001, function () use ($fiber) {
            if ($fiber->isSuspended()) {
                $fiber->resume('data');
            }
        });

        $startResult = $fiber->start();
        expect($startResult)->toBe('waiting');

        runLoopFor(0.01);

        $final = $fiber->isTerminated() ? $fiber->getReturn() : null;

        expect($final)->toBe('completed with data');
    });
});
