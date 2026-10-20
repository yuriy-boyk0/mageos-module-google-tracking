<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Decides the consent state of an order placement from raw request facts and the consent configuration.
 *
 * Pure logic: the caller (Model\RequestContextProvider) reads the cookies and config. The browser-side gate in
 * view/frontend/templates/head.phtml applies the same cookie rules (see isCookieGranted()), so both sides agree.
 */
class ConsentEvaluator
{
    /** Cookie values that mean "accepted" for banners that store a plain flag. */
    private const TRUTHY = ['1', 'true', 'yes', 'y', 'on', 'granted', 'accepted', 'allow', 'allowed'];

    /**
     * @param bool        $isAdminArea      Order placed from the admin panel (no visitor browser involved)
     * @param string|null $cookieValue      Raw value of the configured consent cookie, null when absent
     * @param bool        $hasSessionCookie The request carries a storefront session cookie (a real browser visit)
     * @param int         $websiteId        Website of the order
     * @param bool        $consentRequired  "Require Consent" setting; No = tracking without a consent banner
     * @param string      $cookieName       Configured consent cookie name; '' = the banner only fires a browser event
     * @param string      $grantedValue     Text the cookie must contain to count as accepted; '' = automatic
     */
    public function evaluate(
        bool $isAdminArea,
        ?string $cookieValue,
        bool $hasSessionCookie,
        int $websiteId,
        bool $consentRequired = true,
        string $cookieName = 'user_allowed_save_cookie',
        string $grantedValue = ''
    ): ConsentState {
        if (!$consentRequired) {
            return ConsentState::Granted;
        }
        if ($isAdminArea || trim($cookieName) === '') {
            // No visitor browser, or a banner the server cannot read (event-only): nothing to decide from.
            return ConsentState::Unknown;
        }
        if ($cookieValue === null || $cookieValue === '') {
            // In a real browser visit a missing cookie means the banner was never accepted; without a browser
            // session (webhook, API, cron) there is simply nothing to read.
            return $hasSessionCookie ? ConsentState::Declined : ConsentState::Unknown;
        }

        return $this->isCookieGranted($cookieValue, $websiteId, $grantedValue)
            ? ConsentState::Granted
            : ConsentState::Declined;
    }

    /**
     * Cookie rules (mirrored in head.phtml):
     *  - "Granted Value" configured: the URL-decoded cookie must contain it (e.g. `statistics:true` for Cookiebot).
     *  - otherwise a JSON object/array (Magento's `{"<websiteId>":1}`): the entry for the website must be truthy;
     *  - otherwise a plain flag: 1, true, yes, granted, accepted, allow (case-insensitive).
     */
    public function isCookieGranted(string $rawValue, int $websiteId, string $grantedValue = ''): bool
    {
        $value = rawurldecode($rawValue);
        $grantedValue = trim($grantedValue);
        if ($grantedValue !== '') {
            return str_contains($value, $grantedValue);
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return !empty($decoded[$websiteId]);
        }

        return in_array(strtolower(trim($value)), self::TRUTHY, true);
    }
}
