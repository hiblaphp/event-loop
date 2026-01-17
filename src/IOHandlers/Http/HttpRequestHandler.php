<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Http;

use Hibla\EventLoop\ValueObjects\HttpRequest;

final readonly class HttpRequestHandler
{
    /**
     * @param  string  $url
     * @param  array<int, mixed>  $options
     * @param  callable  $callback
     * @return HttpRequest
     */
    public function createRequest(string $url, array $options, callable $callback): HttpRequest
    {
        return new HttpRequest($url, $options, $callback);
    }

    public function addRequestToMultiHandle(\CurlMultiHandle $multiHandle, HttpRequest $request): bool
    {
        $result = curl_multi_add_handle($multiHandle, $request->getHandle());

        return $result === CURLM_OK;
    }
}
