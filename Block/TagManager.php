<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Block;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;

class TagManager extends Template
{
    private const XML_PATH_ENABLED = 'googletagmanager/general/enabled';
    private const XML_PATH_TAG_ID = 'googletagmanager/general/tag_id';
    private const XML_PATH_GA4_MEASUREMENT = 'googletagmanager/general/ga4_measurement_id';

    public function __construct(
        Context $context,
        private readonly ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE
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
}
