<?php

declare(strict_types=1);

namespace Hibla\EventLoop\Managers;

use CurlMultiHandle;
use Hibla\EventLoop\Interfaces\HttpRequestManagerInterface;
use Hibla\EventLoop\IOHandlers\Http\CurlMultiHandler;
use Hibla\EventLoop\IOHandlers\Http\HttpRequestHandler;
use Hibla\EventLoop\IOHandlers\Http\HttpResponseHandler;
use Hibla\EventLoop\ValueObjects\HttpRequest;

final class HttpRequestManager implements HttpRequestManagerInterface
{
    /**
     *  @var list<HttpRequest>
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

    private readonly HttpRequestHandler $requestHandler;

    private readonly HttpResponseHandler $responseHandler;

    private readonly CurlMultiHandler $curlHandler;

    public function __construct()
    {
        $this->requestHandler = new HttpRequestHandler();
        $this->responseHandler = new HttpResponseHandler();
        $this->curlHandler = new CurlMultiHandler();
        $this->multiHandle = $this->curlHandler->createMultiHandle();
    }

    /**
     * @inheritDoc
     */
    public function addHttpRequest(string $url, array $options, callable $callback): string
    {
        $request = $this->requestHandler->createRequest($url, $options, $callback);
        $requestId = spl_object_hash($request);

        $this->pendingRequests[] = $request;
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

        $this->pendingRequests = array_values(
            array_filter(
                $this->pendingRequests,
                static fn (HttpRequest $r): bool => spl_object_hash($r) !== $requestId
            )
        );

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

            $requestId = spl_object_hash($request);
            unset($this->requestsById[$requestId]);
        }

        $this->activeRequests = [];

        return ['pending' => $pendingCount, 'active' => $activeCount];
    }

    /**
     * @inheritDoc
     */
    public function clearPendingRequests(): int
    {
        $clearedCount = \count($this->pendingRequests);

        if ($clearedCount === 0) {
            return 0;
        }

        foreach ($this->pendingRequests as $request) {
            $request->getCallback()('Request cleared', null, 0, [], null);

            $requestId = spl_object_hash($request);
            unset($this->requestsById[$requestId]);
        }

        $this->pendingRequests = [];

        return $clearedCount;
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

        while (($request = array_shift($this->pendingRequests)) !== null) {
            if ($this->requestHandler->addRequestToMultiHandle($this->multiHandle, $request)) {
                $this->activeRequests[(int) $request->getHandle()] = $request;
            }
        }

        return true;
    }

    private function processActiveRequests(): bool
    {
        if (\count($this->activeRequests) === 0) {
            return false;
        }

        $requestsBefore = $this->activeRequests;

        $this->curlHandler->executeMultiHandle($this->multiHandle);
        $this->responseHandler->processCompletedRequests($this->multiHandle, $this->activeRequests);
        $completedRequests = array_diff_key($requestsBefore, $this->activeRequests);

        // Clean up the completed requests from the master ID map.
        foreach ($completedRequests as $request) {
            $requestId = spl_object_hash($request);
            unset($this->requestsById[$requestId]);
        }

        return \count($completedRequests) > 0;
    }

    public function __destruct()
    {
        $this->curlHandler->closeMultiHandle($this->multiHandle);
    }
}