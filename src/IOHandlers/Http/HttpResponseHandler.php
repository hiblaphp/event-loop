<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Http;

use Hibla\EventLoop\ValueObjects\HttpRequest;
use RuntimeException;

final readonly class HttpResponseHandler
{
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
            default => (\defined('CURL_HTTP_VERSION_3') && $httpVersion === CURL_HTTP_VERSION_3) ? '3.0' : null
        };

        $parsedHeaders = [];
        $headerLines = explode("\r\n", trim($headerStr));
        array_shift($headerLines);

        foreach ($headerLines as $line) {
            $parts = explode(':', $line, 2);
            if (\count($parts) === 2) {
                $name = trim($parts[0]);
                $value = trim($parts[1]);

                if (isset($parsedHeaders[$name])) {
                    if (! \is_array($parsedHeaders[$name])) {
                        $parsedHeaders[$name] = [$parsedHeaders[$name]];
                    }
                    $parsedHeaders[$name][] = $value;
                } else {
                    $parsedHeaders[$name] = $value;
                }
            }
        }

        $request->executeCallback(null, $body, $httpCode, $parsedHeaders, $versionString);
    }

    public function handleErrorResponse(\CurlHandle $handle, HttpRequest $request): void
    {
        $error = curl_error($handle);
        $request->executeCallback($error, null, null, [], null);
    }

    /**
     * @param  \CurlMultiHandle  $multiHandle
     * @param  array<int, HttpRequest>  $activeRequests
     * @return bool Returns true if at least one request was processed, false otherwise.
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
