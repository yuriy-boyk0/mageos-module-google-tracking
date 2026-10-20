<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\MeasurementProtocol;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\HttpResponse;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\HttpTransportInterface;

class FakeTransport implements HttpTransportInterface
{
    /** @var array<int, array{url: string, body: string, timeout: int}> */
    public array $requests = [];

    /**
     * @param HttpResponse|\Throwable|callable $response
     */
    public function __construct(private $response = null)
    {
        $this->response ??= new HttpResponse(204);
    }

    public function postJson(string $url, string $jsonBody, int $timeoutSeconds): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'body' => $jsonBody, 'timeout' => $timeoutSeconds];
        $response = is_callable($this->response) ? ($this->response)($url, $jsonBody) : $this->response;
        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }
}
