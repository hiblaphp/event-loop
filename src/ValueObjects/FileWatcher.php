<?php

declare(strict_types=1);

namespace Hibla\EventLoop\ValueObjects;

final class FileWatcher
{
    private string $id;

    private string $path;

    /**
     * @var callable
     */
    private $callback;

    /**
     * @var array<string, mixed>
     */
    private array $options;

    private float $lastModified;

    private int $lastSize;

    private float $lastChecked;

    /**
     * @param  string  $path
     * @param  callable(string, string): void  $callback
     * @param  array<string, mixed>  $options  Configuration options
     *                                         - polling_interval: float - Time between checks in seconds (default: 0.1)
     *                                         - watch_size: bool - Whether to watch file size changes (default: true)
     *                                         - watch_content: bool - Whether to watch content hash (default: false)
     *
     * @throws \InvalidArgumentException If the callback is not callable
     */
    public function __construct(string $path, callable $callback, array $options = [])
    {
        $this->id = uniqid('watcher_', true);
        $this->path = $path;
        $this->callback = $callback;
        $this->options = array_merge([
            'polling_interval' => 0.1, // Default 100ms for faster detection
            'watch_size' => true,       // Watch file size changes
            'watch_content' => false,   // Watch content hash (expensive)
        ], $options);

        // Initialize with current file state
        if (file_exists($path)) {
            $modTime = filemtime($path);
            $fileSize = filesize($path);

            $this->lastModified = $modTime !== false ? (float) $modTime : 0.0;
            $this->lastSize = $fileSize !== false ? $fileSize : 0;
        } else {
            $this->lastModified = 0.0;
            $this->lastSize = 0;
        }

        $this->lastChecked = microtime(true);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getPollingInterval(): float
    {
        $interval = $this->options['polling_interval'];

        return \is_float($interval) || \is_int($interval) ? (float) $interval : 0.1;
    }

    public function getLastModified(): float
    {
        return $this->lastModified;
    }

    public function updateLastModified(float $time): void
    {
        $this->lastModified = $time;
    }

    public function shouldCheck(): bool
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastChecked;

        if ($elapsed >= $this->getPollingInterval()) {
            $this->lastChecked = $now;

            return true;
        }

        return false;
    }

    public function checkForChanges(): bool
    {
        if (! file_exists($this->path)) {
            // File was deleted
            if ($this->lastModified > 0 || $this->lastSize > 0) {
                $this->lastModified = 0.0;
                $this->lastSize = 0;

                return true;
            }

            return false;
        }

        clearstatcache(true, $this->path);

        $currentModified = filemtime($this->path);
        $currentSize = filesize($this->path);

        // Handle potential false returns from file functions
        if ($currentModified === false || $currentSize === false) {
            return false;
        }

        $hasChanged = false;

        // Check modification time (allow for filesystem timestamp precision)
        $timeDiff = abs((float) $currentModified - $this->lastModified);
        if ($timeDiff > 0.001) {
            $hasChanged = true;
        }

        // Check file size if enabled
        $watchSize = $this->options['watch_size'] ?? false;
        if (! $hasChanged && \is_bool($watchSize) && $watchSize && $currentSize !== $this->lastSize) {
            $hasChanged = true;
        }

        if ($hasChanged) {
            $this->lastModified = (float) $currentModified;
            $this->lastSize = $currentSize;

            return true;
        }

        return false;
    }

    public function executeCallback(string $event, string $path): void
    {
        ($this->callback)($event, $path);
    }
}
