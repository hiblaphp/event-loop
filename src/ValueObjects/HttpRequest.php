<?php

declare(strict_types=1);

namespace Hibla\EventLoop\ValueObjects;

final class HttpRequest
{
    private \CurlHandle $handle;

    /**
     * @var callable(?string, ?string, ?int, array<string, mixed>, ?string): void
     */
    private $callback;

    private string $url;

    private ?string $id = null;

    /**
     * @param  string  $url 
     * @param  array<int|string, mixed>  $options  cURL options array
     * @param  callable(?string, ?string, ?int, array<string, mixed>, ?string): void  $callback  Callback to execute on completion
     */
    public function __construct(string $url, array $options, callable $callback)
    {
        $this->url = $url;
        $this->callback = $callback;
        $this->handle = $this->createCurlHandle($options);
    }

    /**
     * @param  array<int|string, mixed>  $options
     * @return \CurlHandle 
     */
    private function createCurlHandle(array $options): \CurlHandle
    {
        $handle = curl_init();
        curl_setopt_array($handle, $options);

        return $handle;
    }

    public function getHandle(): \CurlHandle
    {
        return $this->handle;
    }

    /**
     * @return callable(?string, ?string, ?int, array<string, mixed>, ?string): void 
     */
    public function getCallback(): callable
    {
        return $this->callback;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @param  string|null  $error 
     * @param  string|null  $response 
     * @param  int|null  $httpCode 
     * @param  array<string, mixed>  $headers  
     * @param  string|null  $httpVersion
     *
     * @throws \Throwable Any exception thrown by the callback is propagated
     */
    public function executeCallback(?string $error, ?string $response, ?int $httpCode, array $headers = [], ?string $httpVersion = null): void
    {
        ($this->callback)($error, $response, $httpCode, $headers, $httpVersion);
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getId(): ?string
    {
        return $this->id;
    }
}
