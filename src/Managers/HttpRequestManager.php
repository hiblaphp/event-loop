<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use CurlHandle;
use CurlMultiHandle;
use Hibla\EventLoop\Interfaces\HttpRequestManagerInterface;
use Hibla\EventLoop\ValueObjects\HttpRequest;
use RuntimeException;

final class HttpRequestManager implements HttpRequestManagerInterface
{
    /**
     * @var array<string, HttpRequest>
     */
    private array $pendingRequests = [];

    /**
     * @var array<int, HttpRequest>
     */
    private array $activeRequests = [];

    /**
     * @var array<string, HttpRequest>
     */
    private array $requestsById = [];

    private readonly CurlMultiHandle $multiHandle;

    public function __construct()
    {
        $this->multiHandle = curl_multi_init();
    }

    /**
     * @inheritDoc
     */
    public function addHttpRequest(string $url, array $options, callable $callback): string
    {
        $request = new HttpRequest($url, $options, $callback);
        $requestId = spl_object_hash($request);

        $this->pendingRequests[$requestId] = $request;
        $this->requestsById[$requestId] = $request;

        return $requestId;
    }

    /**
     * @inheritDoc
     */
    public function cancelHttpRequest(string $requestId): bool
    {
        if (! isset($this->requestsById[$requestId])) {
            return false;
        }

        $request = $this->requestsById[$requestId];

        unset($this->pendingRequests[$requestId]);

        $handle = $request->getHandle();
        $handleId = (int) $handle;

        if (isset($this->activeRequests[$handleId])) {
            curl_multi_remove_handle($this->multiHandle, $handle);
            unset($handle);
            unset($this->activeRequests[$handleId]);
        }

        unset($this->requestsById[$requestId]);

        $request->getCallback()('Request cancelled', null, 0, [], null);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function processRequests(): bool
    {
        $workDone = false;

        if ($this->addPendingRequests()) {
            $workDone = true;
        }

        if ($this->processActiveRequests()) {
            $workDone = true;
        }

        return $workDone;
    }

    /**
     * @inheritDoc
     */
    public function clearAllRequests(): array
    {
        $pendingCount = \count($this->pendingRequests);
        $activeCount = \count($this->activeRequests);

        $this->clearPendingRequests();

        foreach ($this->activeRequests as $request) {
            $handle = $request->getHandle();
            curl_multi_remove_handle($this->multiHandle, $handle);
            unset($handle);
            $request->getCallback()('Request cleared', null, 0, [], null);
            unset($this->requestsById[spl_object_hash($request)]);
        }

        $this->activeRequests = [];

        return ['pending' => $pendingCount, 'active' => $activeCount];
    }

    /**
     * @inheritDoc
     */
    public function clearPendingRequests(): int
    {
        $count = \count($this->pendingRequests);

        if ($count === 0) {
            return 0;
        }

        foreach ($this->pendingRequests as $request) {
            $request->getCallback()('Request cleared', null, 0, [], null);
            unset($this->requestsById[spl_object_hash($request)]);
        }

        $this->pendingRequests = [];

        return $count;
    }

    /**
     * @inheritDoc
     */
    public function hasRequests(): bool
    {
        return \count($this->pendingRequests) > 0 || \count($this->activeRequests) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDebugInfo(): array
    {
        return [
            'pending_count' => \count($this->pendingRequests),
            'active_count' => \count($this->activeRequests),
            'by_id_count' => \count($this->requestsById),
            'active_handles' => array_keys($this->activeRequests),
            'request_ids' => array_keys($this->requestsById),
        ];
    }

    private function addPendingRequests(): bool
    {
        if (\count($this->pendingRequests) === 0) {
            return false;
        }

        foreach ($this->pendingRequests as $request) {
            if (curl_multi_add_handle($this->multiHandle, $request->getHandle()) === CURLM_OK) {
                $this->activeRequests[(int) $request->getHandle()] = $request;
            }
        }

        $this->pendingRequests = [];

        return true;
    }

    private function processActiveRequests(): bool
    {
        if (\count($this->activeRequests) === 0) {
            return false;
        }

        $requestsBefore = $this->activeRequests;

        $this->executeMultiHandle();
        $this->processCompletedRequests();

        $completedRequests = array_diff_key($requestsBefore, $this->activeRequests);

        foreach ($completedRequests as $request) {
            unset($this->requestsById[spl_object_hash($request)]);
        }

        return \count($completedRequests) > 0;
    }

    private function executeMultiHandle(): void
    {
        $running = null;

        do {
            $mrc = curl_multi_exec($this->multiHandle, $running);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM);

        if (! \is_int($running)) {
            throw new RuntimeException('curl_multi_exec failed to update the handle count to an integer.');
        }
    }

    private function processCompletedRequests(): void
    {
        while ($info = curl_multi_info_read($this->multiHandle)) {
            $handle = $info['handle'];

            if (! ($handle instanceof CurlHandle)) {
                throw new RuntimeException('curl_multi_info_read returned an invalid handle type.');
            }

            $handleId = (int) $handle;

            if (! isset($this->activeRequests[$handleId])) {
                continue;
            }

            $request = $this->activeRequests[$handleId];

            if ($info['result'] === CURLE_OK) {
                $this->handleSuccessfulResponse($handle, $request);
            } else {
                $this->handleErrorResponse($handle, $request);
            }

            curl_multi_remove_handle($this->multiHandle, $handle);
            unset($handle);
            unset($this->activeRequests[$handleId]);
        }
    }

    private function handleSuccessfulResponse(CurlHandle $handle, HttpRequest $request): void
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
            default => (\defined('CURL_HTTP_VERSION_3') && $httpVersion === CURL_HTTP_VERSION_3) ? '3.0' : null,
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

    private function handleErrorResponse(CurlHandle $handle, HttpRequest $request): void
    {
        $request->executeCallback(curl_error($handle), null, null, [], null);
    }

    public function __destruct()
    {
        curl_multi_close($this->multiHandle);
    }
}
