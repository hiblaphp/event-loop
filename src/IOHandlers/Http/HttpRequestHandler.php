<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Http;

use Hibla\EventLoop\ValueObjects\HttpRequest;

/**
 * Handles HTTP request creation and multi-handle management.
 *
 * This class manages the lifecycle of HTTP requests within cURL multi-handles,
 * including creation, addition, and removal operations.
 */
final readonly class HttpRequestHandler
{
    /**
     * Create a new HTTP request object.
     *
     * Creates an HttpRequest value object with the specified URL, options,
     * and callback function for handling the response.
     *
     * @param  string  $url  The URL to request
     * @param  array<int, mixed>  $options  cURL options for the request (e.g., [CURLOPT_TIMEOUT => 10])
     * @param  callable  $callback  Callback to execute when request completes
     * @return HttpRequest The created request object
     */
    public function createRequest(string $url, array $options, callable $callback): HttpRequest
    {
        return new HttpRequest($url, $options, $callback);
    }

    /**
     * Add an HTTP request to a cURL multi-handle.
     *
     * Adds the request's cURL handle to the multi-handle so it can be
     * processed concurrently with other requests.
     *
     * @param  \CurlMultiHandle  $multiHandle  The multi-handle to add to
     * @param  HttpRequest  $request  The request to add
     * @return bool True if the request was successfully added
     */
    public function addRequestToMultiHandle(\CurlMultiHandle $multiHandle, HttpRequest $request): bool
    {
        $result = curl_multi_add_handle($multiHandle, $request->getHandle());

        return $result === CURLM_OK;
    }

    /**
     * Remove an HTTP request from a cURL multi-handle.
     *
     * Removes the request's cURL handle from the multi-handle and closes
     * the individual handle to free resources.
     *
     * @param  \CurlMultiHandle  $multiHandle  The multi-handle to remove from
     * @param  HttpRequest  $request  The request to remove
     * @return bool True if the request was successfully removed
     */
    public function removeRequestFromMultiHandle(\CurlMultiHandle $multiHandle, HttpRequest $request): bool
    {
        $result = curl_multi_remove_handle($multiHandle, $request->getHandle());
        curl_close($request->getHandle());

        return $result === CURLM_OK;
    }
}
