<?php

declare(strict_types=1);

namespace Hibla\EventLoop\ValueObjects;

/**
 * File watcher for monitoring file system changes.
 *
 * This class provides functionality to watch files for modifications, deletions,
 * and size changes using a polling mechanism. It's designed to work efficiently
 * within an event loop system for asynchronous file monitoring.
 */
final class FileWatcher
{
    /**
     * Unique identifier for this watcher instance.
     */
    private string $id;

    /**
     * Path to the file being watched.
     */
    private string $path;

    /**
     * Callback function to execute when file changes are detected.
     *
     * @var callable(string, string): void
     */
    private $callback;

    /**
     * Configuration options for the file watcher.
     *
     * @var array<string, mixed>
     */
    private array $options;

    /**
     * Last recorded modification time of the watched file.
     */
    private float $lastModified;

    /**
     * Last recorded size of the watched file in bytes.
     */
    private int $lastSize;

    /**
     * Timestamp of the last check performed.
     */
    private float $lastChecked;

    /**
     * Creates a new file watcher instance.
     *
     * @param  string  $path  Path to the file to watch
     * @param  callable(string, string): void  $callback  Callback to execute on file changes
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

    /**
     * Gets the unique identifier of this watcher.
     *
     * @return string The unique watcher ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Gets the path of the file being watched.
     *
     * @return string The file path
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Gets the callback function for this watcher.
     *
     * @return callable(string, string): void The callback function
     */
    public function getCallback(): callable
    {
        return $this->callback;
    }

    /**
     * Gets the configuration options for this watcher.
     *
     * @return array<string, mixed> The watcher options
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Gets the polling interval in seconds.
     *
     * @return float The polling interval in seconds
     */
    public function getPollingInterval(): float
    {
        $interval = $this->options['polling_interval'];

        return is_float($interval) || is_int($interval) ? (float) $interval : 0.1;
    }

    /**
     * Gets the last recorded modification time.
     *
     * @return float The last modification time as Unix timestamp
     */
    public function getLastModified(): float
    {
        return $this->lastModified;
    }

    /**
     * Updates the last modification time.
     *
     * @param  float  $time  The new modification time as Unix timestamp
     */
    public function updateLastModified(float $time): void
    {
        $this->lastModified = $time;
    }

    /**
     * Determines if it's time to check for file changes based on polling interval.
     *
     * @return bool True if a check should be performed, false otherwise
     */
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

    /**
     * Checks for file changes and updates internal state.
     *
     * This method performs the actual file system check to detect changes in
     * modification time and file size (if enabled). It handles file deletion
     * scenarios and updates the internal state when changes are detected.
     *
     * @return bool True if changes were detected, false otherwise
     */
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
        if (! $hasChanged && is_bool($watchSize) && $watchSize && $currentSize !== $this->lastSize) {
            $hasChanged = true;
        }

        if ($hasChanged) {
            $this->lastModified = (float) $currentModified;
            $this->lastSize = $currentSize;

            return true;
        }

        return false;
    }

    /**
     * Executes the callback function with error handling.
     *
     * @param  string  $event  The type of event that occurred (e.g., 'modified', 'deleted')
     * @param  string  $path  The path of the file that changed
     *
     * @throws \Throwable Any exception thrown by the callback is caught and logged
     */
    public function executeCallback(string $event, string $path): void
    {
        try {
            ($this->callback)($event, $path);
        } catch (\Throwable $e) {
            error_log('File watcher callback error: '.$e->getMessage());
        }
    }
}
