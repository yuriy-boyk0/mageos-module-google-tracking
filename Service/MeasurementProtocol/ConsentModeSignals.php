<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Google Consent Mode v2 settings and the signals derived from them.
 *
 *  off      - no Consent Mode commands; GTM only loads after consent (the module's original behaviour)
 *  basic    - default "denied" for all signals before GTM, GTM still only loads after consent, then "granted"
 *  advanced - default "denied", GTM loads at once (Google sends cookieless pings), "granted" after consent
 *
 * Which signals become "granted" on consent: all four, or only analytics_storage (stores without Google Ads).
 */
class ConsentModeSignals
{
    public const OFF = 'off';
    public const BASIC = 'basic';
    public const ADVANCED = 'advanced';

    public const GRANT_ALL = 'all';
    public const GRANT_ANALYTICS = 'analytics';

    public const SIGNALS = ['ad_storage', 'analytics_storage', 'ad_user_data', 'ad_personalization'];

    public function normalizeMode(string $mode): string
    {
        return in_array($mode, [self::BASIC, self::ADVANCED], true) ? $mode : self::OFF;
    }

    /**
     * Browser signals after the visitor accepted.
     *
     * @return array<string, string>
     */
    public function grantedSignals(string $grantedSignals): array
    {
        $signals = array_fill_keys(self::SIGNALS, 'denied');
        if ($grantedSignals === self::GRANT_ANALYTICS) {
            $signals['analytics_storage'] = 'granted';

            return $signals;
        }

        return array_fill_keys(self::SIGNALS, 'granted');
    }

    /**
     * The Measurement Protocol `consent` object (only the two ad signals exist there), or null when Consent Mode
     * is off (nothing is sent, as before).
     *
     * @return array{ad_user_data: string, ad_personalization: string}|null
     */
    public function measurementProtocolConsent(string $mode, string $grantedSignals, ConsentState $state): ?array
    {
        if ($this->normalizeMode($mode) === self::OFF) {
            return null;
        }
        $ads = $state === ConsentState::Granted && $grantedSignals !== self::GRANT_ANALYTICS ? 'GRANTED' : 'DENIED';

        return ['ad_user_data' => $ads, 'ad_personalization' => $ads];
    }
}
