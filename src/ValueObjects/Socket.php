<?php

declare(strict_types=1);

namespace Hibla\EventLoop\ValueObjects;

/**
 * Socket value object representing socket connection data.
 *
 * This is a pure value object that holds socket-related data without dependencies
 * or business logic. It's immutable and focused on data representation.
 */
final class Socket
{
    /**
     * The underlying socket resource.
     *
     * @var resource|null
     */
    private $resource;

    /**
     * Whether the socket has been marked as closed.
     */
    private bool $isClosed;

    /**
     * Socket metadata (address, port, etc.)
     *
     * @var array<string, mixed>
     */
    private array $metadata;

    /**
     * Creates a new Socket value object.
     *
     * @param  resource|null  $resource  The socket resource
     * @param  bool  $isClosed  Whether the socket is closed
     * @param  array<string, mixed>  $metadata  Additional socket metadata
     *
     * @throws \TypeError If resource is not a valid resource type or null
     */
    public function __construct($resource, bool $isClosed = false, array $metadata = [])
    {
        if (! is_resource($resource) && $resource !== null) {
            throw new \TypeError('Expected resource or null, got '.gettype($resource));
        }

        $this->resource = $resource;
        $this->isClosed = $isClosed;
        $this->metadata = $metadata;
    }

    /**
     * Gets the underlying socket resource.
     *
     * @return resource|null The socket resource
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * Checks if the socket is marked as closed.
     *
     * @return bool True if the socket is closed, false otherwise
     */
    public function isClosed(): bool
    {
        return $this->isClosed;
    }

    /**
     * Gets socket metadata.
     *
     * @return array<string, mixed> The metadata array
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Gets a specific metadata value.
     *
     * @param  string  $key  The metadata key
     * @param  mixed  $default  Default value if key doesn't exist
     * @return mixed The metadata value or default
     */
    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Creates a new Socket instance with updated closed status.
     *
     * @param  bool  $isClosed  The new closed status
     * @return self A new Socket instance
     */
    public function withClosedStatus(bool $isClosed): self
    {
        return new self($this->resource, $isClosed, $this->metadata);
    }

    /**
     * Creates a new Socket instance with additional metadata.
     *
     * @param  array<string, mixed>  $metadata  The metadata to merge
     * @return self A new Socket instance
     */
    public function withMetadata(array $metadata): self
    {
        return new self($this->resource, $this->isClosed, array_merge($this->metadata, $metadata));
    }

    /**
     * Creates a new Socket instance with a single metadata value.
     *
     * @param  string  $key  The metadata key
     * @param  mixed  $value  The metadata value
     * @return self A new Socket instance
     */
    public function withMetadataValue(string $key, mixed $value): self
    {
        $newMetadata = $this->metadata;
        $newMetadata[$key] = $value;

        return new self($this->resource, $this->isClosed, $newMetadata);
    }

    /**
     * Checks if the socket resource is valid.
     *
     * @return bool True if resource is valid, false otherwise
     */
    public function hasValidResource(): bool
    {
        return is_resource($this->resource);
    }

    /**
     * Gets the socket type from metadata.
     *
     * @return string|null The socket type or null if not set
     */
    public function getType(): ?string
    {
        $value = $this->getMetadataValue('type');

        return is_string($value) ? $value : null;
    }

    /**
     * Gets the socket address from metadata.
     *
     * @return string|null The socket address or null if not set
     */
    public function getAddress(): ?string
    {
        $value = $this->getMetadataValue('address');

        return is_string($value) ? $value : null;
    }

    /**
     * Gets the socket port from metadata.
     *
     * @return int|null The socket port or null if not set
     */
    public function getPort(): ?int
    {
        $value = $this->getMetadataValue('port');

        return is_int($value) ? $value : null;
    }

    /**
     * String representation of the socket.
     */
    public function __toString(): string
    {
        $address = $this->getAddress() ?? 'unknown';
        $port = $this->getPort() ?? 'unknown';
        $status = $this->isClosed ? 'closed' : 'open';

        return "Socket({$address}:{$port}, {$status})";
    }

    /**
     * Compare two Socket instances for equality.
     *
     * @param  Socket  $other  The other socket to compare
     * @return bool True if sockets are equal, false otherwise
     */
    public function equals(Socket $other): bool
    {
        return $this->resource === $other->resource
            && $this->isClosed === $other->isClosed
            && $this->metadata === $other->metadata;
    }
}
