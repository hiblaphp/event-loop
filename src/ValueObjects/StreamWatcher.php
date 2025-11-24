<?php

declare(strict_types=1);

namespace Hibla\EventLoop\ValueObjects;

/**
 * Stream watcher for monitoring stream resource readiness.
 *
 * This class provides functionality to watch stream resources for read or write
 * readiness within an event loop system. It encapsulates the stream resource,
 * callback function, and watch type for asynchronous I/O operations.
 */
final class StreamWatcher
{
    /**
     * Constant for read stream watching.
     */
    public const TYPE_READ = 'read';

    /**
     * Constant for write stream watching.
     */
    public const TYPE_WRITE = 'write';

    /**
     * Unique identifier for this stream watcher.
     */
    private string $id;

    /**
     * The stream resource being watched.
     *
     * @var resource
     */
    private $stream;

    /**
     * Callback function to execute when stream is ready.
     *
     * @var callable(resource): void
     */
    private $callback;

    /**
     * Type of watching operation (read or write).
     */
    private string $type;

    /**
     * Creates a new stream watcher instance.
     *
     * @param  resource  $stream  The stream resource to watch
     * @param  callable(resource): void  $callback  Callback to execute when stream is ready
     * @param  string  $type  Type of operation (TYPE_READ or TYPE_WRITE)
     *
     * @throws \TypeError If stream is not a valid resource type
     * @throws \InvalidArgumentException If type is not valid
     */
    public function __construct($stream, callable $callback, string $type = self::TYPE_READ)
    {
        if (! is_resource($stream)) {
            throw new \TypeError('Expected resource, got '.gettype($stream));
        }

        if (! in_array($type, [self::TYPE_READ, self::TYPE_WRITE], true)) {
            throw new \InvalidArgumentException('Type must be either TYPE_READ or TYPE_WRITE');
        }

        $this->id = uniqid('sw_', true);
        $this->stream = $stream;
        $this->callback = $callback;
        $this->type = $type;
    }

    /**
     * Gets the unique identifier of this stream watcher.
     *
     * @return string The unique watcher ID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Gets the stream resource being watched.
     *
     * @return resource The stream resource
     */
    public function getStream()
    {
        return $this->stream;
    }

    /**
     * Gets the type of watching operation.
     *
     * @return string The watch type (TYPE_READ or TYPE_WRITE)
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Gets the callback function for this watcher.
     *
     * @return callable(resource): void The callback function
     */
    public function getCallback(): callable
    {
        return $this->callback;
    }

    /**
     * Executes the callback function with the watched stream.
     *
     * This method is called when the stream becomes ready for the
     * specified operation (read or write).
     *
     *
     * @throws \Throwable Any exception thrown by the callback is propagated
     */
    public function execute(): void
    {
        ($this->callback)($this->stream);
    }
}
