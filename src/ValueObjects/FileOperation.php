<?php

declare(strict_types=1);

namespace Hibla\EventLoop\ValueObjects;

final class FileOperation
{
    private string $id;

    private string $type;

    private string $path;

    private mixed $data;

    /**
     * @var callable
     */
    private $callback;

    /**
     * @var array<string, mixed>
     */
    private array $options;

    private float $createdAt;

    private bool $cancelled = false;
    
    /**
     * @var callable|null
     */
    private $scheduledCallback = null;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        string $type,
        string $path,
        mixed $data,
        callable $callback,
        array $options = []
    ) {
        $this->id = uniqid('file_', true);
        $this->type = $type;
        $this->path = $path;
        $this->data = $data;
        $this->callback = $callback;
        $this->options = $options;
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

    public function getCallback(): callable
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

    public function setScheduledCallback(callable $callback): void
    {
        $this->scheduledCallback = $callback;
    }

    public function getScheduledCallback(): ?callable
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
