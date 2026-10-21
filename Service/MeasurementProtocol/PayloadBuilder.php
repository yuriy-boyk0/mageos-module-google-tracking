<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Builds the Measurement Protocol JSON payload for a purchase from a normalized order snapshot.
 *
 * Snapshot shape (produced by Model\OrderSnapshotFactory):
 *  transaction_id string, currency string, value float, tax float, shipping float, coupon ?string,
 *  created_at_micros ?int, user_id ?string,
 *  items list of [item_id, item_name, price, quantity, discount?, item_category..item_category5?, item_variant?]
 */
class PayloadBuilder
{
    public const DEFAULT_EVENT_NAME = 'purchase_server';

    private const MAX_ITEMS = 200;
    private const ITEM_STRING_KEYS = [
        'item_id', 'item_name', 'item_variant', 'item_brand', 'affiliation',
        'item_list_id', 'item_list_name',
        'item_category', 'item_category2', 'item_category3', 'item_category4', 'item_category5',
    ];

    public function build(
        array $snapshot,
        string $eventName,
        string $clientId,
        ?string $sessionId = null,
        bool $includeUserId = false,
        ?array $consent = null
    ): array {
        $params = [
            'transaction_id' => (string) $snapshot['transaction_id'],
            'currency' => strtoupper((string) $snapshot['currency']),
            'value' => round((float) $snapshot['value'], 2),
            'tax' => round((float) ($snapshot['tax'] ?? 0), 2),
            'shipping' => round((float) ($snapshot['shipping'] ?? 0), 2),
            'items' => $this->buildItems($snapshot['items'] ?? []),
            // Required by GA4 for the event to count as engaged activity in the session.
            'engagement_time_msec' => 1,
        ];

        $coupon = (string) ($snapshot['coupon'] ?? '');
        if ($coupon !== '') {
            $params['coupon'] = $coupon;
        }
        if ($sessionId !== null && $sessionId !== '') {
            $params['session_id'] = $sessionId;
        }

        $payload = [
            'client_id' => $clientId,
            'events' => [['name' => $this->sanitizeEventName($eventName), 'params' => $params]],
        ];

        // Lets GA4 accept events that are sent late (queue retries); GA4 allows up to 72 hours back.
        if (!empty($snapshot['created_at_micros'])) {
            $payload['timestamp_micros'] = (int) $snapshot['created_at_micros'];
        }
        if ($includeUserId && !empty($snapshot['user_id'])) {
            $payload['user_id'] = (string) $snapshot['user_id'];
        }
        // Consent Mode v2 ad signals (ConsentModeSignals::measurementProtocolConsent), only when Consent Mode is on.
        if ($consent !== null) {
            $payload['consent'] = $consent;
        }

        return $payload;
    }

    /**
     * GA4 event names: letters, digits and underscores, starting with a letter, max 40 characters.
     */
    public function sanitizeEventName(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}$/', $name) === 1) {
            return $name;
        }

        return self::DEFAULT_EVENT_NAME;
    }

    /**
     * GA4 item objects from snapshot items (also used for refunds).
     */
    public function buildItems(array $items): array
    {
        $result = [];
        foreach (array_slice(array_values($items), 0, self::MAX_ITEMS) as $item) {
            $row = [
                'price' => round((float) ($item['price'] ?? 0), 2),
                'quantity' => max(1, (int) round((float) ($item['quantity'] ?? 1))),
            ];
            if (isset($item['discount']) && (float) $item['discount'] > 0) {
                $row['discount'] = round((float) $item['discount'], 2);
            }
            foreach (self::ITEM_STRING_KEYS as $key) {
                $value = (string) ($item[$key] ?? '');
                if ($value !== '') {
                    $row[$key] = $value;
                }
            }
            $result[] = $row;
        }

        return $result;
    }
}
