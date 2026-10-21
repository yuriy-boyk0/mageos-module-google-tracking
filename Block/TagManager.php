<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Block;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;
use MageOS\GoogleTagManager\Service\DataLayer\PageContext;

class TagManager extends Template
{
    public const XML_PATH_GTM_SCRIPT_URL = 'googletagmanager/general/gtm_script_url';
    public const DEFAULT_GTM_URL = 'https://www.googletagmanager.com';
    private const XML_PATH_ENABLED = 'googletagmanager/general/enabled';
    private const XML_PATH_TAG_ID = 'googletagmanager/general/tag_id';
    private const XML_PATH_GA4_MEASUREMENT = 'googletagmanager/general/ga4_measurement_id';
    private const XML_PATH_CONSENT_REQUIRED = 'googletagmanager/consent/required';
    private const XML_PATH_CONSENT_COOKIE = 'googletagmanager/consent/cookie_name';
    private const XML_PATH_CONSENT_GRANTED_VALUE = 'googletagmanager/consent/granted_value';
    private const XML_PATH_CONSENT_EVENTS = 'googletagmanager/consent/event_names';
    private const XML_PATH_CONSENT_MODE = 'googletagmanager/consent/consent_mode';
    private const XML_PATH_DEBUG_LOGGER = 'googletagmanager/general/debug_logger';
    private const XML_PATH_CONSENT_CMP = 'googletagmanager/consent/cmp_managed';
    private const XML_PATH_CONSENT_SIGNALS = 'googletagmanager/consent/granted_signals';

    public function __construct(
        Context $context,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PageContext $pageContext,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Also false for customer groups listed in "Don't Track Customer Groups" (safe with the full page cache: the
     * customer group is part of Magento's cache key).
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)
            && !$this->pageContext->isCurrentGroupExcluded();
    }

    /**
     * Base URL GTM is loaded from: Google's, or a first-party / server-side GTM domain ("Custom GTM Script URL").
     */
    public function getGtmBaseUrl(): string
    {
        $url = rtrim(trim((string) $this->scopeConfig->getValue(self::XML_PATH_GTM_SCRIPT_URL, ScopeInterface::SCOPE_STORE)), '/');

        return preg_match('#^https://[^/\s]+#', $url) === 1 ? $url : self::DEFAULT_GTM_URL;
    }

    /**
     * {page_type, customer_group, logged_in}, pushed before GTM loads.
     */
    public function getJsonPageContext(): string
    {
        return (string) json_encode(
            $this->pageContext->dataLayerContext($this->fullActionName()),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }

    public function getTagId(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_TAG_ID,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getGA4MeasurementId(): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_GA4_MEASUREMENT,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function isConsentRequired(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_CONSENT_REQUIRED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Settings for the consent gate in head.phtml (same rules as Service\MeasurementProtocol\ConsentEvaluator).
     */
    public function getJsonConsentConfig(): string
    {
        $events = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->scopeConfig->getValue(self::XML_PATH_CONSENT_EVENTS, ScopeInterface::SCOPE_STORE))
        ), 'strlen'));

        return (string) json_encode([
            'required' => $this->isConsentRequired(),
            'cookieName' => trim((string) $this->scopeConfig->getValue(self::XML_PATH_CONSENT_COOKIE, ScopeInterface::SCOPE_STORE)),
            'grantedValue' => trim((string) $this->scopeConfig->getValue(self::XML_PATH_CONSENT_GRANTED_VALUE, ScopeInterface::SCOPE_STORE)),
            'events' => $events,
            'consentMode' => (string) $this->scopeConfig->getValue(self::XML_PATH_CONSENT_MODE, ScopeInterface::SCOPE_STORE) ?: 'off',
            'cmpManaged' => $this->scopeConfig->isSetFlag(self::XML_PATH_CONSENT_CMP, ScopeInterface::SCOPE_STORE),
            'grantedSignals' => (string) $this->scopeConfig->getValue(self::XML_PATH_CONSENT_SIGNALS, ScopeInterface::SCOPE_STORE) ?: 'all',
            'websiteId' => (int) $this->_storeManager->getStore()->getWebsiteId(),
            'containerId' => $this->getTagId(),
            'gtmBaseUrl' => $this->getGtmBaseUrl(),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    /**
     * The debug logger script is rendered; it only logs in browsers switched on with ?gtm_debug=1.
     */
    public function isDebugLoggerAllowed(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DEBUG_LOGGER, ScopeInterface::SCOPE_STORE);
    }

    private function fullActionName(): string
    {
        $request = $this->getRequest();

        return $request instanceof \Magento\Framework\App\Request\Http ? (string) $request->getFullActionName() : '';
    }
}
