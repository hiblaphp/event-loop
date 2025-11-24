<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Http;

use Hibla\EventLoop\ValueObjects\HttpRequest;
use RuntimeException;

/**
 * Handles processing of completed cURL requests from a multi-handle.
 *
 * This class is responsible for reading completion information from a cURL
 * multi-handle, parsing successful responses and errors, and invoking the
 * appropriate callbacks on the corresponding HttpRequest objects.
 */
final readonly class HttpResponseHandler
{
    /**
     * Handles a successfully completed HTTP request.
     *
     * This method is called when a cURL handle completes with a result of CURLE_OK.
     * It extracts the HTTP status code, headers, and body from the response,
     * then executes the request's callback with the parsed data.
     *
     * @param  \CurlHandle  $handle  The individual cURL handle that has completed.
     * @param  HttpRequest  $request  The original request object associated with the handle.
     */
    public function handleSuccessfulResponse(\CurlHandle $handle, HttpRequest $request): void
    {
        $fullResponse = curl_multi_getcontent($handle) ?? '';
        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $headerStr = substr($fullResponse, 0, $headerSize);
        $body = substr($fullResponse, $headerSize);

        $httpVersion = curl_getinfo($handle, CURLINFO_HTTP_VERSION);
        $versionString = match ($httpVersion) {
            CURL_HTTP_VERSION_1_0 => '1.0',
            CURL_HTTP_VERSION_1_1 => '1.1',
            CURL_HTTP_VERSION_2_0 => '2.0',
            default => (defined('CURL_HTTP_VERSION_3') && $httpVersion === CURL_HTTP_VERSION_3) ? '3.0' : null
        };

        $parsedHeaders = [];
        $headerLines = explode("\r\n", trim($headerStr));
        array_shift($headerLines);

        foreach ($headerLines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $name = trim($parts[0]);
                $value = trim($parts[1]);

                if (isset($parsedHeaders[$name])) {
                    if (! is_array($parsedHeaders[$name])) {
                        $parsedHeaders[$name] = [$parsedHeaders[$name]];
                    }
                    $parsedHeaders[$name][] = $value;
                } else {
                    $parsedHeaders[$name] = $value;
                }
            }
        }

        // Pass the HTTP version information to the callback
        $request->executeCallback(null, $body, $httpCode, $parsedHeaders, $versionString);
    }

    /**
     * Handles a failed HTTP request.
     *
     * This method is called when a cURL handle completes with an error. It
     * retrieves the cURL error message and executes the request's callback,
     * passing the error.
     *
     * @param  \CurlHandle  $handle  The individual cURL handle that has failed.
     * @param  HttpRequest  $request  The original request object associated with the handle.
     */
    public function handleErrorResponse(\CurlHandle $handle, HttpRequest $request): void
    {
        $error = curl_error($handle);
        $request->executeCallback($error, null, null, [], null);
    }

    /**
     * Processes all completed requests from a cURL multi-handle.
     *
     * This method iterates through any completed cURL handles in the multi-handle,
     * determines if they succeeded or failed, and dispatches them to the
     * appropriate handler method. It also cleans up by removing the handle from
     * the multi-handle and closing it.
     *
     * @param  \CurlMultiHandle  $multiHandle  The cURL multi-handle to process.
     * @param  array<int, HttpRequest>  &$activeRequests  An associative array of active requests,
     *                                                    keyed by their integer handle ID. This
     *                                                    array is modified by this method.
     * @return bool Returns true if at least one request was processed, false otherwise.
     *
     * @throws RuntimeException If curl_multi_info_read returns an invalid handle type.
     */
    public function processCompletedRequests(\CurlMultiHandle $multiHandle, array &$activeRequests): bool
    {
        $processed = false;

        while ($info = curl_multi_info_read($multiHandle)) {
            $handle = $info['handle'];
            if (! ($handle instanceof \CurlHandle)) {
                throw new RuntimeException('curl_multi_info_read returned an invalid handle type.');
            }

            $handleId = (int) $handle;

            if (isset($activeRequests[$handleId])) {
                $request = $activeRequests[$handleId];

                if ($info['result'] === CURLE_OK) {
                    $this->handleSuccessfulResponse($handle, $request);
                } else {
                    $this->handleErrorResponse($handle, $request);
                }

                curl_multi_remove_handle($multiHandle, $handle);
                curl_close($handle);
                unset($activeRequests[$handleId]);
                $processed = true;
            }
        }

        return $processed;
    }
}
