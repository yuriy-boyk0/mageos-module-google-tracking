<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model\Csp;

use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\GoogleTagManager\Block\TagManager;

/**
 * Allows the host of a "Custom GTM Script URL" (first-party / server-side GTM) in the Content Security Policy,
 * next to the static googletagmanager.com entries of etc/csp_whitelist.xml.
 */
class GtmScriptHostCollector implements PolicyCollectorInterface
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {}

    public function collect(array $defaultPolicies = []): array
    {
        $url = trim((string) $this->scopeConfig->getValue(TagManager::XML_PATH_GTM_SCRIPT_URL, ScopeInterface::SCOPE_STORE));
        $host = $url === '' ? '' : (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return $defaultPolicies;
        }
        foreach (['script-src', 'connect-src', 'img-src', 'frame-src'] as $directive) {
            $defaultPolicies[] = new FetchPolicy($directive, false, [$host]);
        }

        return $defaultPolicies;
    }
}
