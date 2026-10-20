<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Decides the consent state of an order placement from raw request facts.
 *
 * Pure logic: the caller (RequestContextProvider) reads the cookies. Mirrors the client-side gate in
 * view/frontend/templates/head.phtml, which only loads GTM when the `user_allowed_save_cookie` cookie
 * has a truthy entry for the current website id.
 */
class ConsentEvaluator
{
    /**
     * @param bool        $isAdminArea     Order placed from the admin panel (no visitor browser involved)
     * @param string|null $consentCookie   Raw value of the `user_allowed_save_cookie` cookie (url-encoded JSON map website id => 1)
     * @param bool        $hasSessionCookie Whether the request carries a storefront session cookie (a real browser visit)
     */
    public function evaluate(
        bool $isAdminArea,
        ?string $consentCookie,
        bool $hasSessionCookie,
        int $websiteId
    ): ConsentState {
        if ($isAdminArea) {
            return ConsentState::Unknown;
        }

        $accepted = $this->parseConsentCookie($consentCookie);
        if ($accepted !== null) {
            return !empty($accepted[$websiteId]) ? ConsentState::Granted : ConsentState::Declined;
        }

        // No (readable) consent cookie. In a real browser visit that means the banner was never accepted;
        // without a browser session either (webhook, API, cron) there is simply nothing to read.
        return $hasSessionCookie ? ConsentState::Declined : ConsentState::Unknown;
    }

    /**
     * @return array<int|string, mixed>|null null when the cookie is missing or not parseable
     */
    private function parseConsentCookie(?string $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode(rawurldecode($value), true);

        return is_array($decoded) ? $decoded : null;
    }
}
