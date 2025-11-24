<?php

declare(strict_types=1);

namespace Hibla\EventLoop\IOHandlers\Http;

use RuntimeException;

/**
 * Handles cURL multi-handle operations for concurrent HTTP requests.
 *
 * This class provides low-level operations for managing cURL multi-handles,
 * which enable processing multiple HTTP requests concurrently.
 */
final readonly class CurlMultiHandler
{
    /**
     * Execute all handles in a multi-handle and return the number still running.
     *
     * Performs the actual HTTP request processing for all handles in the
     * multi-handle. May need to be called multiple times until all requests
     * are complete.
     *
     * @param  \CurlMultiHandle  $multiHandle  The multi-handle to execute
     * @return int Number of handles still running
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

    /**
     * Create a new cURL multi-handle.
     *
     * Initializes a new multi-handle that can be used to process
     * multiple cURL requests concurrently.
     *
     * @return \CurlMultiHandle The newly created multi-handle
     */
    public function createMultiHandle(): \CurlMultiHandle
    {
        return curl_multi_init();
    }

    /**
     * Close and clean up a cURL multi-handle.
     *
     * Properly closes the multi-handle and frees associated resources.
     * Should be called when the multi-handle is no longer needed.
     *
     * @param  \CurlMultiHandle  $multiHandle  The multi-handle to close
     */
    public function closeMultiHandle(\CurlMultiHandle $multiHandle): void
    {
        curl_multi_close($multiHandle);
    }
}
