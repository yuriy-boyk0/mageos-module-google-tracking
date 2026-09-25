<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;

class EventQueue
{
    public const SESSION_KEY_ADD_TO_CART = 'gtm_add_to_cart_event';
    public const SESSION_KEY_REMOVE_FROM_CART = 'gtm_remove_from_cart_event';
    public const SESSION_KEY_ADD_TO_WISHLIST = 'gtm_add_to_wishlist_event';
    public const SESSION_KEY_REMOVE_WISHLIST = 'gtm_remove_from_wishlist_event';
    public const SESSION_KEY_UPDATE_CART = 'gtm_update_cart_event';
    public const SESSION_KEY_CUSTOMER_LOGIN = 'gtm_customer_login_event';

    public const SESSION_KEY_CUSTOMER_SIGN_UP = 'gtm_customer_sign_up_event';
    public const COOKIE_LOGOUT = 'gtm_logout_event';

    private const COOKIE_DURATION = 60;

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly SerializerInterface $serializer
    ) {}

    public function enqueueSessionEvent(string $sessionKey, array $eventData): void
    {
        $this->checkoutSession->setData($sessionKey, $eventData);
    }

    public function dequeueSessionEvent(string $sessionKey): ?array
    {
        $data = $this->checkoutSession->getData($sessionKey);
        $this->checkoutSession->unsetData($sessionKey);
        return is_array($data) ? $data : null;
    }

    public function enqueueLogoutCookie(array $eventData): void
    {
        $metadata = $this->cookieMetadataFactory
            ->createPublicCookieMetadata()
            ->setDuration(self::COOKIE_DURATION)
            ->setPath('/')
            ->setHttpOnly(false)
            ->setSameSite('Lax');

        $this->cookieManager->setPublicCookie(
            self::COOKIE_LOGOUT,
            $this->serializer->serialize($eventData),
            $metadata
        );
    }
}
