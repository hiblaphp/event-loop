<?php

declare(strict_types=1);

namespace Hibla\EventLoop\ValueObjects;

final class FileOperation
{
    private string $id;

    private float $createdAt;

    private bool $cancelled = false;

    /**
     * @var callable|null
     */
    private $scheduledCallback = null;

    /**
     * @param  string  $type
     * @param  string  $path
     * @param  mixed  $data
     * @param  callable  $callback
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        private readonly string $type,
        private readonly string $path,
        private readonly mixed $data,
        private readonly mixed $callback,
        private readonly array $options = []
    ) {
        $this->id = uniqid('file_', true);
        $this->createdAt = microtime(true);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * @return callable
     */
    public function getCallback()
    {
        return $this->callback;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function getCreatedAt(): float
    {
        return $this->createdAt;
    }

    /**
     * @param  callable  $callback
     */
    public function setScheduledCallback($callback): void
    {
        $this->scheduledCallback = $callback;
    }

    /**
     * @return callable|null
     */
    public function getScheduledCallback()
    {
        return $this->scheduledCallback;
    }

    public function cancel(): void
    {
        $this->cancelled = true;
        $this->scheduledCallback = null;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function executeCallback(?string $error, mixed $result = null): void
    {
        if ($this->cancelled) {
            return;
        }

        try {
            ($this->callback)($error, $result);
        } catch (\Throwable $e) {
            error_log('File operation callback error: ' . $e->getMessage());

            throw $e;
        }
    }
}