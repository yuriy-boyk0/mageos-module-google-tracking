<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\FollowUpPayloadBuilder;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\HoldPolicy;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ItemIdResolver;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\PayloadBuilder;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\SendSettings;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\SendSettingsProviderInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\TotalsResolver;

/**
 * Reads the server-side (Measurement Protocol) configuration group.
 */
class Config implements SendSettingsProviderInterface
{
    private const PATH = 'googletagmanager/server_side/';
    private const PATH_GA4_MEASUREMENT_ID = 'googletagmanager/general/ga4_measurement_id';
    private const PATH_CONSENT = 'googletagmanager/consent/';

    public const CURRENCY_DISPLAY = 'display';
    public const CURRENCY_BASE = 'base';
    public const UNKNOWN_CONSENT_SEND = 'send';
    public const UNKNOWN_CONSENT_SKIP = 'skip';

    private const DEFAULT_MAX_ATTEMPTS = 8;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {}

    public function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH . 'enabled', ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * The server-side Measurement ID, falling back to the GA4 Measurement ID of the General group.
     */
    public function getMeasurementId(int $storeId): string
    {
        $own = trim((string) $this->value('measurement_id', $storeId));
        if ($own !== '') {
            return $own;
        }

        return trim((string) $this->scopeConfig->getValue(
            self::PATH_GA4_MEASUREMENT_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getApiSecret(int $storeId): string
    {
        $raw = (string) $this->value('api_secret', $storeId);

        return $raw === '' ? '' : trim($this->encryptor->decrypt($raw));
    }

    public function getEventName(int $storeId): string
    {
        $name = trim((string) $this->value('event_name', $storeId));

        return $name === '' ? PayloadBuilder::DEFAULT_EVENT_NAME : $name;
    }

    public function isConsentRequired(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH_CONSENT . 'required', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getConsentCookieName(int $storeId): string
    {
        return trim((string) $this->scopeConfig->getValue(self::PATH_CONSENT . 'cookie_name', ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getConsentGrantedValue(int $storeId): string
    {
        return trim((string) $this->scopeConfig->getValue(self::PATH_CONSENT . 'granted_value', ScopeInterface::SCOPE_STORE, $storeId));
    }

    public function getConsentMode(int $storeId): string
    {
        return (string) $this->scopeConfig->getValue(self::PATH_CONSENT . 'consent_mode', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getConsentModeGrantedSignals(int $storeId): string
    {
        return (string) $this->scopeConfig->getValue(self::PATH_CONSENT . 'granted_signals', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isRefundTrackingEnabled(int $storeId): bool
    {
        return $this->isEnabled($storeId) && $this->flag('track_refunds', $storeId);
    }

    public function isCancellationTrackingEnabled(int $storeId): bool
    {
        return $this->isEnabled($storeId) && $this->flag('track_cancellations', $storeId);
    }

    public function getRefundEventName(int $storeId): string
    {
        $name = trim((string) $this->value('refund_event_name', $storeId));

        return $name === '' ? FollowUpPayloadBuilder::DEFAULT_REFUND_EVENT : $name;
    }

    public function getCancelEventName(int $storeId): string
    {
        $name = trim((string) $this->value('cancel_event_name', $storeId));

        return $name === '' ? FollowUpPayloadBuilder::DEFAULT_CANCEL_EVENT : $name;
    }

    /**
     * Days to keep sent and skipped rows (0 = forever). Read at default scope: the cleanup is global.
     */
    public function getRetentionDays(): int
    {
        return max(0, (int) $this->scopeConfig->getValue(self::PATH . 'retention_days'));
    }

    /**
     * Days a purchase may be held for an excluded order status before the daily cleanup closes it as expired
     * (0 = no limit). Read at default scope, like the retention: the cleanup is global.
     */
    public function getMaxHoldDays(): int
    {
        $value = $this->scopeConfig->getValue(self::PATH . 'max_hold_days');

        return $value === null || $value === '' ? HoldPolicy::DEFAULT_MAX_HOLD_DAYS : max(0, (int) $value);
    }

    public function getCurrencyBasis(int $storeId): string
    {
        return $this->value('currency_basis', $storeId) === self::CURRENCY_BASE
            ? self::CURRENCY_BASE
            : self::CURRENCY_DISPLAY;
    }

    public function getTaxBasis(int $storeId): string
    {
        return $this->value('totals_tax', $storeId) === TotalsResolver::EXCLUDING_TAX
            ? TotalsResolver::EXCLUDING_TAX
            : TotalsResolver::INCLUDING_TAX;
    }

    public function getItemIdMode(int $storeId): string
    {
        return $this->value('item_id_mode', $storeId) === ItemIdResolver::CHILD
            ? ItemIdResolver::CHILD
            : ItemIdResolver::PARENT;
    }

    public function getUnknownConsentAction(int $storeId): string
    {
        return $this->value('unknown_consent', $storeId) === self::UNKNOWN_CONSENT_SKIP
            ? self::UNKNOWN_CONSENT_SKIP
            : self::UNKNOWN_CONSENT_SEND;
    }

    /**
     * @return array{exclude_zero_value: bool, excluded_statuses: string[], excluded_payment_methods: string[], include_admin_orders: bool}
     */
    public function getExclusionRules(int $storeId): array
    {
        return [
            // General setting: also applies to the client-side purchase event (Block\CheckoutTagManager).
            'exclude_zero_value' => $this->scopeConfig->isSetFlag('googletagmanager/general/exclude_zero_value', ScopeInterface::SCOPE_STORE, $storeId),
            'excluded_statuses' => $this->list('excluded_statuses', $storeId),
            'excluded_payment_methods' => $this->list('excluded_payment_methods', $storeId),
            'include_admin_orders' => $this->flag('include_admin_orders', $storeId),
            // General settings, also applied on the client side (Block\TagManager / Block\CheckoutTagManager).
            'excluded_customer_groups' => array_map('intval', array_filter(explode(',', (string) $this->scopeConfig->getValue(
                'googletagmanager/general/excluded_customer_groups', ScopeInterface::SCOPE_STORE, $storeId
            )), 'strlen')),
            'max_transaction_value' => (float) $this->scopeConfig->getValue(
                'googletagmanager/general/max_transaction_value', ScopeInterface::SCOPE_STORE, $storeId
            ),
        ];
    }

    public function getSettings(int $storeId): ?SendSettings
    {
        if (!$this->isEnabled($storeId)) {
            return null;
        }
        $measurementId = $this->getMeasurementId($storeId);
        $apiSecret = $this->getApiSecret($storeId);
        if ($measurementId === '' || $apiSecret === '') {
            return null;
        }
        $attempts = (int) $this->value('max_attempts', $storeId);

        return new SendSettings(
            $measurementId,
            $apiSecret,
            $attempts > 0 ? $attempts : self::DEFAULT_MAX_ATTEMPTS,
            $this->flag('debug', $storeId),
            $this->flag('debug_view', $storeId)
        );
    }

    private function value(string $field, int $storeId): mixed
    {
        return $this->scopeConfig->getValue(self::PATH . $field, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function flag(string $field, int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::PATH . $field, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @return string[]
     */
    private function list(string $field, int $storeId): array
    {
        $raw = (string) $this->value($field, $storeId);

        return $raw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
    }
}
