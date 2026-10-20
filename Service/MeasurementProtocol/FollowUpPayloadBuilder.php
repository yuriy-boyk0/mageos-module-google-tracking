<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Builds the server-side refund / cancellation payload for an order whose purchase was tracked server-side.
 *
 * The visitor identifiers (client_id, user_id) are copied from the stored purchase payload, so the follow-up is
 * attributed to the same (or the same anonymous) visitor. No session_id or timestamp: it happens now, outside the
 * shopping session.
 */
class FollowUpPayloadBuilder
{
    public const DEFAULT_REFUND_EVENT = 'refund_server';
    public const DEFAULT_CANCEL_EVENT = 'cancel_server';

    public function __construct(
        private readonly PayloadBuilder $payloadBuilder
    ) {}

    /**
     * @param array $purchasePayload the decoded payload stored for the order's purchase row
     * @param array{transaction_id: string, currency: string, value: float, items?: array} $amounts
     * @param array<string, scalar> $extraParams e.g. ['refund_type' => 'partial']
     */
    public function build(array $purchasePayload, string $eventName, string $fallbackName, array $amounts, array $extraParams = []): array
    {
        $params = [
            'transaction_id' => (string) $amounts['transaction_id'],
            'currency' => strtoupper((string) $amounts['currency']),
            'value' => round((float) $amounts['value'], 2),
            'engagement_time_msec' => 1,
        ];
        $items = $this->payloadBuilder->buildItems($amounts['items'] ?? []);
        if ($items !== []) {
            $params['items'] = $items;
        }
        $params += $extraParams;

        $name = $this->payloadBuilder->sanitizeEventName($eventName);
        if ($name === PayloadBuilder::DEFAULT_EVENT_NAME && trim($eventName) !== PayloadBuilder::DEFAULT_EVENT_NAME) {
            $name = $fallbackName; // an invalid configured name falls back to the follow-up's own default, not to purchase_server
        }

        $payload = [
            'client_id' => (string) ($purchasePayload['client_id'] ?? ''),
            'events' => [['name' => $name, 'params' => $params]],
        ];
        if (!empty($purchasePayload['user_id'])) {
            $payload['user_id'] = (string) $purchasePayload['user_id'];
        }

        return $payload;
    }
}
