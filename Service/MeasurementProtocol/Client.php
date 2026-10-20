<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Sends one payload to the GA4 Measurement Protocol. Never throws: every outcome is a SendResult.
 *
 * The regular endpoint answers 2xx for almost anything (even invalid payloads), so a successful
 * response only proves delivery to Google, not that the event is valid. Use the debug endpoint
 * (debug mode in the configuration) to validate payloads.
 */
class Client
{
    public const ENDPOINT = 'https://www.google-analytics.com/mp/collect';
    public const DEBUG_ENDPOINT = 'https://www.google-analytics.com/debug/mp/collect';

    private const TIMEOUT_SECONDS = 5;

    public function __construct(
        private readonly HttpTransportInterface $transport
    ) {}

    public function send(string $measurementId, string $apiSecret, array $payload, bool $debug = false): SendResult
    {
        $url = ($debug ? self::DEBUG_ENDPOINT : self::ENDPOINT)
            . '?' . http_build_query(['measurement_id' => $measurementId, 'api_secret' => $apiSecret]);

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            // A payload that cannot be encoded will never succeed: do not retry.
            return new SendResult(false, false, null, 'Payload could not be encoded: ' . $e->getMessage());
        }

        try {
            $response = $this->transport->postJson($url, $body, self::TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            return new SendResult(false, true, null, 'Network error: ' . $e->getMessage());
        }

        $status = $response->statusCode;
        if ($status >= 200 && $status < 300) {
            return $debug ? $this->debugResult($response) : new SendResult(true, false, $status);
        }

        // 408/429 and server errors are transient. Other 4xx (bad secret, bad request) will not fix themselves.
        $retryable = $status === 408 || $status === 429 || $status >= 500;

        return new SendResult(false, $retryable, $status, 'HTTP ' . $status);
    }

    private function debugResult(HttpResponse $response): SendResult
    {
        $decoded = json_decode($response->body, true);
        $messages = [];
        if (is_array($decoded)) {
            foreach ($decoded['validationMessages'] ?? [] as $message) {
                $messages[] = is_array($message)
                    ? trim(($message['fieldPath'] ?? '') . ': ' . ($message['description'] ?? ''), ': ')
                    : (string) $message;
            }
        }

        // The debug endpoint validates only; nothing is recorded. "Success" here means the payload is valid.
        return new SendResult(
            $messages === [],
            false,
            $response->statusCode,
            $messages === [] ? 'Payload valid (debug endpoint, event not recorded)' : 'Payload rejected by validation',
            $messages
        );
    }
}
