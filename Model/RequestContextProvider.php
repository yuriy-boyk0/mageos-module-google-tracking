<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Stdlib\CookieManagerInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ConsentEvaluator;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ConsentState;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\GaCookieParser;

/**
 * Reads consent and GA identifiers from the current request (the order placement request carries the
 * visitor's cookies). Thin Magento adapter around the pure ConsentEvaluator and GaCookieParser.
 */
class RequestContextProvider
{
    // Magento\Cookie\Helper\Cookie::IS_USER_ALLOWED_SAVE_COOKIE, the cookie head.phtml checks before loading GTM
    private const CONSENT_COOKIE = 'user_allowed_save_cookie';
    private const SESSION_COOKIE = 'PHPSESSID';
    private const GA_COOKIE = '_ga';

    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly State $appState,
        private readonly ConsentEvaluator $consentEvaluator,
        private readonly GaCookieParser $gaCookieParser
    ) {}

    public function isAdminArea(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_ADMINHTML;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getConsentState(int $websiteId): ConsentState
    {
        return $this->consentEvaluator->evaluate(
            $this->isAdminArea(),
            $this->cookieManager->getCookie(self::CONSENT_COOKIE),
            $this->cookieManager->getCookie(self::SESSION_COOKIE) !== null,
            $websiteId
        );
    }

    public function getClientId(): ?string
    {
        return $this->gaCookieParser->parseClientId($this->cookieManager->getCookie(self::GA_COOKIE));
    }

    public function getSessionId(string $measurementId): ?string
    {
        if ($measurementId === '') {
            return null;
        }

        return $this->gaCookieParser->parseSessionId(
            $this->cookieManager->getCookie($this->gaCookieParser->sessionCookieName($measurementId))
        );
    }
}
