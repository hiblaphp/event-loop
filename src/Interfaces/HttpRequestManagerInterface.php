<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Interfaces;

/**
 * Interface for managing HTTP request lifecycle.
 */
interface HttpRequestManagerInterface
{
    /**
     * Adds an HTTP request to the processing queue.
     *
     * @param  string  $url  The request URL
     * @param  array<int, mixed>  $options  cURL options
     * @param  callable  $callback  The completion callback
     * @return string The request ID
     */
    public function addHttpRequest(string $url, array $options, callable $callback): string;

    /**
     * Cancels a pending or active HTTP request.
     *
     * @param  string  $requestId  The request ID to cancel
     * @return bool True if cancelled, false if not found
     */
    public function cancelHttpRequest(string $requestId): bool;

    /**
     * Processes pending and active requests for one tick.
     *
     * @return bool True if any work was done
     */
    public function processRequests(): bool;

    /**
     * Checks if there are any pending or active requests.
     *
     * @return bool True if there are requests
     */
    public function hasRequests(): bool;

    /**
     * Clears all HTTP requests.
     *
     * @return array{pending: int, active: int} Count of cleared requests
     */
    public function clearAllRequests(): array;
}
