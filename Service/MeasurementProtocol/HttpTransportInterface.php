<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

interface HttpTransportInterface
{
    /**
     * POST a JSON body. Implementations must throw on network-level failures (DNS, connect, timeout)
     * and return an HttpResponse for any HTTP response, whatever its status code.
     *
     * @throws \RuntimeException
     */
    public function postJson(string $url, string $jsonBody, int $timeoutSeconds): HttpResponse;
}
