<?php

declare(strict_types=1);

use Hibla\EventLoop\EventLoop;

beforeEach(function () {
    EventLoop::reset();
});

afterEach(function () {
    EventLoop::reset();
});

describe('EventLoop Auto-Run Feature', function () {

    it('automatically runs the loop when work is scheduled', function () {
        $testFile = tempnam(sys_get_temp_dir(), 'event_loop_test_');

        $code = <<<'PHP'
<?php
require 'vendor/autoload.php';

use Hibla\EventLoop\EventLoop;

$loop = EventLoop::getInstance();

$loop->addTimer(0.1, function () {
    file_put_contents('%s', 'executed');
});
PHP;

        $code = sprintf($code, $testFile);
        $scriptFile = tempnam(sys_get_temp_dir(), 'script_') . '.php';
        file_put_contents($scriptFile, $code);

        exec("php $scriptFile");

        expect(file_exists($testFile))->toBeTrue()
            ->and(file_get_contents($testFile))->toBe('executed')
        ;

        unlink($testFile);
        unlink($scriptFile);
    });

    it('does not run twice when explicitly started', function () {
        $testFile = tempnam(sys_get_temp_dir(), 'event_loop_test_');

        $code = <<<'PHP'
<?php
require 'vendor/autoload.php';

use Hibla\EventLoop\EventLoop;

$loop = EventLoop::getInstance();

$loop->addTimer(0.1, function () {
    $content = file_exists('%s') ? file_get_contents('%s') : '';
    file_put_contents('%s', $content . 'X');
});

$loop->run();
PHP;

        $code = sprintf($code, $testFile, $testFile, $testFile);
        $scriptFile = tempnam(sys_get_temp_dir(), 'script_') . '.php';
        file_put_contents($scriptFile, $code);

        exec("php $scriptFile");

        expect(file_get_contents($testFile))->toBe('X');

        unlink($testFile);
        unlink($scriptFile);
    });

    it('does not auto-run when stop is called', function () {
        $testFile = sys_get_temp_dir() . '/event_loop_test_' . uniqid() . '.txt';

        $code = <<<'PHP'
<?php
require 'vendor/autoload.php';

use Hibla\EventLoop\EventLoop;

$loop = EventLoop::getInstance();

$loop->addTimer(0.1, function () {
    file_put_contents('%s', 'executed');
});

$loop->stop();
PHP;

        $code = sprintf($code, $testFile);
        $scriptFile = tempnam(sys_get_temp_dir(), 'script_') . '.php';
        file_put_contents($scriptFile, $code);

        exec("php $scriptFile");

        expect(file_exists($testFile))->toBeFalse();

        if (file_exists($testFile)) {
            unlink($testFile);
        }
        unlink($scriptFile);
    });

    it('does not auto-run when there is no work', function () {
        $testFile = tempnam(sys_get_temp_dir(), 'event_loop_test_');

        $code = <<<'PHP'
<?php
require 'vendor/autoload.php';

use Hibla\EventLoop\EventLoop;

$loop = EventLoop::getInstance();

file_put_contents('%s', 'no-auto-run');
PHP;

        $code = sprintf($code, $testFile);
        $scriptFile = tempnam(sys_get_temp_dir(), 'script_') . '.php';
        file_put_contents($scriptFile, $code);

        exec("php $scriptFile");

        expect(file_get_contents($testFile))->toBe('no-auto-run');

        unlink($testFile);
        unlink($scriptFile);
    });

    it('executes nextTick callbacks via auto-run', function () {
        $testFile = tempnam(sys_get_temp_dir(), 'event_loop_test_');

        $code = <<<'PHP'
<?php
require 'vendor/autoload.php';

use Hibla\EventLoop\EventLoop;

$loop = EventLoop::getInstance();

$loop->nextTick(function () {
    file_put_contents('%s', 'next-tick');
});
PHP;

        $code = sprintf($code, $testFile);
        $scriptFile = tempnam(sys_get_temp_dir(), 'script_') . '.php';
        file_put_contents($scriptFile, $code);

        exec("php $scriptFile");

        expect(file_get_contents($testFile))->toBe('next-tick');

        unlink($testFile);
        unlink($scriptFile);
    });

    it('executes deferred callbacks via auto-run', function () {
        $testFile = tempnam(sys_get_temp_dir(), 'event_loop_test_');

        $code = <<<'PHP'
<?php
require 'vendor/autoload.php';

use Hibla\EventLoop\EventLoop;

$loop = EventLoop::getInstance();

$loop->defer(function () {
    file_put_contents('%s', 'deferred');
});
PHP;

        $code = sprintf($code, $testFile);
        $scriptFile = tempnam(sys_get_temp_dir(), 'script_') . '.php';
        file_put_contents($scriptFile, $code);

        exec("php $scriptFile");

        expect(file_get_contents($testFile))->toBe('deferred');

        unlink($testFile);
        unlink($scriptFile);
    });

    it('executes periodic timers via auto-run', function () {
        $testFile = tempnam(sys_get_temp_dir(), 'event_loop_test_');

        $code = <<<'PHP'
<?php
require 'vendor/autoload.php';

use Hibla\EventLoop\EventLoop;

$loop = EventLoop::getInstance();

$loop->addPeriodicTimer(0.05, function () {
    $content = file_exists('%s') ? file_get_contents('%s') : '';
    file_put_contents('%s', $content . 'X');
}, 3);
PHP;

        $code = sprintf($code, $testFile, $testFile, $testFile);
        $scriptFile = tempnam(sys_get_temp_dir(), 'script_') . '.php';
        file_put_contents($scriptFile, $code);

        exec("php $scriptFile");

        expect(file_get_contents($testFile))->toBe('XXX');

        unlink($testFile);
        unlink($scriptFile);
    });

    it('does not auto-run when forceStop is called', function () {
        $testFile = sys_get_temp_dir() . '/event_loop_test_' . uniqid() . '.txt';

        $code = <<<'PHP'
<?php
require 'vendor/autoload.php';

use Hibla\EventLoop\EventLoop;

$loop = EventLoop::getInstance();

$loop->addTimer(0.1, function () {
    file_put_contents('%s', 'executed');
});

$loop->forceStop();
PHP;

        $code = sprintf($code, $testFile);
        $scriptFile = tempnam(sys_get_temp_dir(), 'script_') . '.php';
        file_put_contents($scriptFile, $code);

        exec("php $scriptFile");

        expect(file_exists($testFile))->toBeFalse();

        if (file_exists($testFile)) {
            unlink($testFile);
        }
        unlink($scriptFile);
    });

    it('clears all work when forceStop is called', function () {
        $testFile = sys_get_temp_dir() . '/event_loop_test_' . uniqid() . '.txt';

        $code = <<<'PHP'
<?php
require 'vendor/autoload.php';

use Hibla\EventLoop\EventLoop;

$loop = EventLoop::getInstance();

// Add multiple types of work
$loop->addTimer(0.1, function () use (&$counter) {
    file_put_contents('%s', 'timer');
});

$loop->nextTick(function () use (&$counter) {
    file_put_contents('%s', 'nexttick');
});

$loop->defer(function () use (&$counter) {
    file_put_contents('%s', 'deferred');
});

// Force stop should clear everything
$loop->forceStop();

// Try to run - should do nothing since work was cleared
$loop->run();
PHP;

        $code = sprintf($code, $testFile, $testFile, $testFile);
        $scriptFile = tempnam(sys_get_temp_dir(), 'script_') . '.php';
        file_put_contents($scriptFile, $code);

        exec("php $scriptFile");

        expect(file_exists($testFile))->toBeFalse();

        if (file_exists($testFile)) {
            unlink($testFile);
        }
        unlink($scriptFile);
    });
});
