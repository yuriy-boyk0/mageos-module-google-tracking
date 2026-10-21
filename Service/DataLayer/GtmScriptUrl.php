<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\DataLayer;

/**
 * Rules for the "Custom GTM Script URL": an origin only, https://host or https://host:port (a trailing slash is
 * dropped). No path, query, credentials or plain http. The same rule is used when the setting is saved, when the
 * storefront builds the gtm.js URL, and when the host is added to the Content Security Policy, so an invalid value
 * neither loads GTM from it nor opens the CSP to it.
 */
class GtmScriptUrl
{
    private const PATTERN = '#^https://((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(:[0-9]{1,5})?/?$#';

    /**
     * The origin without trailing slash, or null when the value is empty or not a valid origin.
     */
    public function normalize(string $url): ?string
    {
        $url = strtolower(trim($url));
        if ($url === '' || preg_match(self::PATTERN, $url, $match) !== 1) {
            return null;
        }
        $port = $match[2] ?? '';
        if ($port !== '' && ((int) substr($port, 1) < 1 || (int) substr($port, 1) > 65535)) {
            return null;
        }

        return 'https://' . $match[1] . $port;
    }

    /**
     * Host of a valid value (for the CSP), or '' when the value is empty or invalid.
     */
    public function host(string $url): string
    {
        $origin = $this->normalize($url);

        return $origin === null ? '' : (string) parse_url($origin, PHP_URL_HOST);
    }
}
