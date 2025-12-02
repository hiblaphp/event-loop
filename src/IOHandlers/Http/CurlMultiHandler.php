<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Http;

use RuntimeException;

final readonly class CurlMultiHandler
{
    /**
     * @param  \CurlMultiHandle  $multiHandle 
     * @return int 
     */
    public function executeMultiHandle(\CurlMultiHandle $multiHandle): int
    {
        $running = null;

        do {
            $mrc = curl_multi_exec($multiHandle, $running);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM);

        if (! is_int($running)) {
            throw new RuntimeException('curl_multi_exec failed to update the handle count to an integer.');
        }

        return $running;
    }

    public function createMultiHandle(): \CurlMultiHandle
    {
        return curl_multi_init();
    }

    public function closeMultiHandle(\CurlMultiHandle $multiHandle): void
    {
        curl_multi_close($multiHandle);
    }
}
