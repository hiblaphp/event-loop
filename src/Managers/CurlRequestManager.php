<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use CurlHandle;
use CurlMultiHandle;
use Hibla\EventLoop\Interfaces\CurlRequestManagerInterface;
use Hibla\EventLoop\ValueObjects\CurlRequest;
use RuntimeException;

final class CurlRequestManager implements CurlRequestManagerInterface
{
    /**
     * @var array<string, CurlRequest>
     */
    private array $pendingRequests = [];

    /**
     * @var array<int, CurlRequest>
     */
    private array $activeRequests = [];

    /**
     * @var array<string, CurlRequest>
     */
    private array $requestsById = [];

    private readonly CurlMultiHandle $multiHandle;

    public function __construct()
    {
        if (! extension_loaded('curl')) {
            throw new RuntimeException(
                'ext-curl is required to use HTTP request features. ' .
                    'Install the curl extension or avoid calling addCurlRequest().'
            );
        }

        $this->multiHandle = curl_multi_init();
    }

    /**
     * @inheritDoc
     */
    public function addCurlRequest(string $url, array $options, callable $callback): string
    {
        $request = new CurlRequest($url, $options, $callback);
        $requestId = spl_object_hash($request);

        $this->pendingRequests[$requestId] = $request;
        $this->requestsById[$requestId] = $request;

        return $requestId;
    }

    /**
     * @inheritDoc
     */
    public function cancelCurlRequest(string $requestId): bool
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
    public function waitForActivity(float $timeout): int
    {
        if (\count($this->activeRequests) === 0) {
            return 0;
        }

        $active = curl_multi_select($this->multiHandle, $timeout);

        if ($active === -1) {
            // A return value of -1 means a select error occurred.
            // Sleep briefly to prevent a tight CPU loop.
            usleep(100);
        }

        return $active;
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

        curl_multi_exec($this->multiHandle, $running);

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
            unset($this->activeRequests[$handleId]);
        }
    }

    private function handleSuccessfulResponse(CurlHandle $handle, CurlRequest $request): void
    {
        $body = curl_multi_getcontent($handle) ?? '';
        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $httpVersion = curl_getinfo($handle, CURLINFO_HTTP_VERSION);

        $versionString = match ($httpVersion) {
            CURL_HTTP_VERSION_1_0 => '1.0',
            CURL_HTTP_VERSION_1_1 => '1.1',
            CURL_HTTP_VERSION_2_0 => '2.0',
            default => (\defined('CURL_HTTP_VERSION_3') && $httpVersion === CURL_HTTP_VERSION_3) ? '3.0' : null,
        };

        $parsedHeaders = $request->getCapturedHeaders();

        $request->executeCallback(null, $body, $httpCode, $parsedHeaders, $versionString);
    }

    private function handleErrorResponse(CurlHandle $handle, CurlRequest $request): void
    {
        $request->executeCallback(curl_error($handle), null, null, [], null);
    }

    public function __destruct()
    {
        curl_multi_close($this->multiHandle);
    }
}
