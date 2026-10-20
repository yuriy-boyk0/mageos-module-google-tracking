<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model;

use MageOS\GoogleTagManager\Service\MeasurementProtocol\HttpResponse;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\HttpTransportInterface;

/**
 * Plain PHP cURL transport (the curl extension is a Magento requirement).
 */
class CurlTransport implements HttpTransportInterface
{
    public function postJson(string $url, string $jsonBody, int $timeoutSeconds): HttpResponse
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialise cURL');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException($error !== '' ? $error : 'cURL request failed');
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new HttpResponse($status, (string) $body);
    }
}
