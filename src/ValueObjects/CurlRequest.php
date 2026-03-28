<?php

declare(strict_types=1);

namespace Hibla\EventLoop\ValueObjects;

final class CurlRequest
{
    private \CurlHandle $handle;

    /**
     * @var callable(?string, ?string, ?int, array<string, mixed>, ?string): void
     */
    private $callback;

    /**
     *  @var non-empty-string
     */
    private string $url;

    /**
     *  @var array<string, string|string[]>
     */
    private array $capturedHeaders = [];

    private ?string $id = null;

    /**
     * @param  string  $url
     * @param  array<int|string, mixed>  $options  cURL options array
     * @param  callable(?string, ?string, ?int, array<string, mixed>, ?string): void  $callback  Callback to execute on completion
     */
    public function __construct(string $url, array $options, callable $callback)
    {
        if ($url === '') {
            throw new \InvalidArgumentException('HTTP Request URL cannot be empty.');
        }

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
        curl_setopt($handle, CURLOPT_URL, $this->url);

        $hasWriteFunction = isset($options[CURLOPT_WRITEFUNCTION]);

        /** @var (callable(\CurlHandle, string): int)|null $userHeaderFunction */
        $userHeaderFunction = isset($options[CURLOPT_HEADERFUNCTION]) && is_callable($options[CURLOPT_HEADERFUNCTION])
            ? $options[CURLOPT_HEADERFUNCTION]
            : null;

        curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($ch, string $headerLine) use ($userHeaderFunction): int {
            $trimmed = trim($headerLine);

            if (stripos($trimmed, 'HTTP/') === 0) {
                $this->capturedHeaders = [];
            } elseif (str_contains($trimmed, ':')) {
                [$name, $value] = explode(':', $trimmed, 2);
                $name = strtolower(trim($name));
                $value = trim($value);

                if (isset($this->capturedHeaders[$name])) {
                    if (! \is_array($this->capturedHeaders[$name])) {
                        $this->capturedHeaders[$name] = [$this->capturedHeaders[$name]];
                    }
                    $this->capturedHeaders[$name][] = $value;
                } else {
                    $this->capturedHeaders[$name] = $value;
                }
            }

            if ($userHeaderFunction !== null) {
                assert($ch instanceof \CurlHandle);

                return $userHeaderFunction($ch, $headerLine);
            }

            return \strlen($headerLine);
        });

        if (! $hasWriteFunction) {
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($handle, CURLOPT_HEADER, false);
        }

        return $handle;
    }

    /**
     * @return array<string, string|string[]>
     */
    public function getCapturedHeaders(): array
    {
        return $this->capturedHeaders;
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
