<?php

declare(strict_types=1);

namespace Hibla\EventLoop\ValueObjects;

final class StreamWatcher
{
    public const string TYPE_READ = 'read';

    public const string TYPE_WRITE = 'write';

    private string $id;

    /**
     * @var resource
     */
    private $stream;

    /**
     * @var callable(resource): void
     */
    private $callback;

    private string $type;

    /**
     * @param  resource  $stream 
     * @param  callable(resource): void  $callback 
     * @param  string  $type
     */
    public function __construct($stream, callable $callback, string $type = self::TYPE_READ)
    {
        if (! \is_resource($stream)) {
            throw new \TypeError('Expected resource, got ' . gettype($stream));
        }

        if (! \in_array($type, [self::TYPE_READ, self::TYPE_WRITE], true)) {
            throw new \InvalidArgumentException('Type must be either TYPE_READ or TYPE_WRITE');
        }

        $this->id = uniqid('sw_', true);
        $this->stream = $stream;
        $this->callback = $callback;
        $this->type = $type;
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return resource 
     */
    public function getStream()
    {
        return $this->stream;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return callable(resource): void 
     */
    public function getCallback(): callable
    {
        return $this->callback;
    }

    public function execute(): void
    {
        ($this->callback)($this->stream);
    }
}
