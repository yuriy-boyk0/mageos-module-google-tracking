<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\MeasurementProtocol;

/**
 * Extracts the GA4 client id and session id from the first-party `_ga` / `_ga_<ID>` cookies, so the
 * server-side event is attributed to the same browser and session as the client-side hits.
 */
class GaCookieParser
{
    /**
     * `_ga` looks like `GA1.1.1234567890.1700000000`; the client id is the last two parts.
     */
    public function parseClientId(?string $cookie): ?string
    {
        if ($cookie === null) {
            return null;
        }
        if (preg_match('/^GA\d+\.\d+\.(\d+\.\d+)$/', trim($cookie), $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * `_ga_<container>` has two formats:
     *  - `GS1.1.<session_id>.<count>.<engaged>.<last_hit>...`
     *  - `GS2.1.s<session_id>$o<count>$g<engaged>$t<last_hit>...`
     */
    public function parseSessionId(?string $cookie): ?string
    {
        if ($cookie === null) {
            return null;
        }
        $cookie = trim($cookie);
        if (preg_match('/^GS1\.\d+\.(\d+)\./', $cookie, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^GS2\.\d+\.s(\d+)(?:\$|$)/', $cookie, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /**
     * Name of the session cookie for a Measurement ID: `G-ABC123` => `_ga_ABC123`.
     */
    public function sessionCookieName(string $measurementId): string
    {
        return '_ga_' . preg_replace('/^G-/', '', $measurementId);
    }
}
