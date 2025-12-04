<?php

declare(strict_types=1);

use Hibla\EventLoop\EventLoopFactory;

describe('EventLoop Error Handling', function () {
    beforeEach(function () {
        EventLoopFactory::reset();
    });

    afterEach(function () {
        EventLoopFactory::reset();
    });

    it('propagates timer callback exceptions', function () {
        $loop = EventLoopFactory::getInstance();
        $goodCallbackExecuted = false;

        $loop->addTimer(0.001, function () {
            throw new Exception('Timer callback error');
        });

        $loop->addTimer(0.002, function () use (&$goodCallbackExecuted) {
            $goodCallbackExecuted = true;
        });

        $loop->addTimer(0.003, function () use ($loop) {
            $loop->stop();
        });

        expect(fn () => $loop->run())->toThrow(Exception::class, 'Timer callback error');

        expect($goodCallbackExecuted)->toBeFalse();
    });

    it('propagates nextTick callback exceptions', function () {
        $loop = EventLoopFactory::getInstance();
        $executed = 0;

        $loop->nextTick(function () {
            throw new Exception('NextTick error');
        });

        $loop->nextTick(function () use (&$executed) {
            $executed++;
        });

        $loop->nextTick(function () use (&$executed) {
            $executed++;
        });

        $loop->addTimer(0.001, function () use ($loop) {
            $loop->stop();
        });

        expect(fn () => $loop->run())->toThrow(Exception::class, 'NextTick error');

        expect($executed)->toBe(0);
    });

    it('propagates fiber exceptions', function () {
        $loop = EventLoopFactory::getInstance();

        $badFiber = new Fiber(function () {
            throw new Exception('Fiber error');
        });

        $loop->addFiber($badFiber);

        $loop->addTimer(0.001, function () use ($loop) {
            $loop->stop();
        });

        expect(fn () => $loop->run())->toThrow(Exception::class, 'Fiber error');
    });

    it('propagates stream callback exceptions when stream is ready', function () {
        $loop = EventLoopFactory::getInstance();
        $exceptionThrown = false;

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            $this->markTestSkipped('Unable to create socket pair');

            return;
        }

        [$read, $write] = $sockets;
        stream_set_blocking($read, false);
        stream_set_blocking($write, false);

        fwrite($write, 'test');

        $loop->addStreamWatcher($read, function () use (&$exceptionThrown) {
            $exceptionThrown = true;

            throw new Exception('Stream callback error');
        });

        $loop->addTimer(0.010, function () use ($loop) {
            $loop->stop();
        });

        try {
            $loop->run();
            if ($exceptionThrown) {
                expect(true)->toBeFalse('Exception should have been thrown');
            }
        } catch (Exception $e) {
            expect($e->getMessage())->toBe('Stream callback error');
            expect($exceptionThrown)->toBeTrue();
        } finally {
            fclose($read);
            fclose($write);
        }
    })->skipOnWindows();

    it('allows developers to handle timer exceptions with try-catch', function () {
        $loop = EventLoopFactory::getInstance();
        $exceptionHandled = false;
        $goodCallbackExecuted = false;

        $loop->addTimer(0.001, function () use (&$exceptionHandled) {
            try {
                throw new Exception('Timer callback error');
            } catch (Exception $e) {
                $exceptionHandled = true;
            }
        });

        $loop->addTimer(0.002, function () use (&$goodCallbackExecuted) {
            $goodCallbackExecuted = true;
        });

        $loop->addTimer(0.003, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        expect($exceptionHandled)->toBeTrue();
        expect($goodCallbackExecuted)->toBeTrue();
    });

    it('allows developers to handle nextTick exceptions with try-catch', function () {
        $loop = EventLoopFactory::getInstance();
        $exceptionHandled = false;
        $executed = 0;

        $loop->nextTick(function () use (&$exceptionHandled) {
            try {
                throw new Exception('NextTick error');
            } catch (Exception $e) {
                $exceptionHandled = true;
            }
        });

        $loop->nextTick(function () use (&$executed) {
            $executed++;
        });

        $loop->nextTick(function () use (&$executed) {
            $executed++;
        });

        $loop->addTimer(0.001, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        expect($exceptionHandled)->toBeTrue();
        expect($executed)->toBe(2);
    });

    it('demonstrates fiber exception handling with internal try-catch', function () {
        $loop = EventLoopFactory::getInstance();
        $exceptionHandled = false;
        $fiberCompleted = false;

        $safeFiber = new Fiber(function () use (&$exceptionHandled, &$fiberCompleted) {
            try {
                throw new Exception('Fiber operation failed');
            } catch (Exception $e) {
                $exceptionHandled = true;
            }
            $fiberCompleted = true;
        });

        $loop->addFiber($safeFiber);

        $loop->addTimer(0.001, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        expect($exceptionHandled)->toBeTrue();
        expect($fiberCompleted)->toBeTrue();
    });

    it('shows multiple fibers run independently with error isolation', function () {
        $loop = EventLoopFactory::getInstance();
        $fiber1Executed = false;
        $fiber2Executed = false;
        $fiber3Executed = false;

        $fiber1 = new Fiber(function () use (&$fiber1Executed) {
            $fiber1Executed = true;
        });

        $fiber2 = new Fiber(function () use (&$fiber2Executed) {
            try {
                throw new Exception('Fiber 2 error');
            } catch (Exception $e) {
                $fiber2Executed = true;
            }
        });

        $fiber3 = new Fiber(function () use (&$fiber3Executed) {
            $fiber3Executed = true;
        });

        $loop->addFiber($fiber1);
        $loop->addFiber($fiber2);
        $loop->addFiber($fiber3);

        $loop->addTimer(0.001, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        expect($fiber1Executed)->toBeTrue();
        expect($fiber2Executed)->toBeTrue();
        expect($fiber3Executed)->toBeTrue();
    });

    it('propagates resource cleanup errors', function () {
        $loop = EventLoopFactory::getInstance();

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            $this->markTestSkipped('Unable to create socket pair');

            return;
        }

        [$read, $write] = $sockets;
        stream_set_blocking($read, false);
        fwrite($write, 'data');

        $loop->addStreamWatcher($read, function () {
            throw new Exception('Stream processing error');
        });

        $loop->addTimer(0.010, function () use ($loop) {
            $loop->stop();
        });

        try {
            $loop->run();
            expect(true)->toBeFalse('Exception should have been thrown');
        } catch (Exception $e) {
            expect($e->getMessage())->toBe('Stream processing error');
        } finally {
            fclose($read);
            fclose($write);
        }
    })->skipOnWindows();

    it('handles memory pressure without exceptions', function () {
        $loop = EventLoopFactory::getInstance();
        $operationCount = 10000;

        for ($i = 0; $i < $operationCount; $i++) {
            $loop->nextTick(function () {
                $data = str_repeat('x', 1000);
                unset($data);
            });
        }

        $loop->addTimer(0.001, function () use ($loop) {
            $loop->stop();
        });

        $startMemory = memory_get_usage();
        $loop->run();
        $endMemory = memory_get_usage();

        expect($endMemory - $startMemory)->toBeLessThan(10 * 1024 * 1024);
    });

    it('demonstrates proper error handling pattern', function () {
        $loop = EventLoopFactory::getInstance();
        $errors = [];
        $successCount = 0;

        for ($i = 0; $i < 5; $i++) {
            $loop->addTimer(0.001 * $i, function () use ($i, &$errors, &$successCount) {
                try {
                    if ($i === 2) {
                        throw new Exception("Operation $i failed");
                    }
                    $successCount++;
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            });
        }

        $loop->addTimer(0.010, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        expect($errors)->toHaveCount(1);
        expect($errors[0])->toBe('Operation 2 failed');
        expect($successCount)->toBe(4);
    });

    it('demonstrates stream error handling pattern', function () {
        $loop = EventLoopFactory::getInstance();

        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        [$read, $write] = $sockets;
        stream_set_blocking($read, false);
        fwrite($write, 'data');

        $errorHandled = false;
        $successCallbackExecuted = false;

        $loop->addStreamWatcher($read, function () use (&$errorHandled) {
            try {
                throw new Exception('Stream processing error');
            } catch (Exception $e) {
                $errorHandled = true;
            }
        });

        $loop->addTimer(0.001, function () use (&$successCallbackExecuted) {
            $successCallbackExecuted = true;
        });

        $loop->addTimer(0.002, function () use ($loop) {
            $loop->stop();
        });

        $loop->run();

        expect($errorHandled)->toBeTrue();
        expect($successCallbackExecuted)->toBeTrue();

        fclose($read);
        fclose($write);
    })->skipOnWindows();
});
