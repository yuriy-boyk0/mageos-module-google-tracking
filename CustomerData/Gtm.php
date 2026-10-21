<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\CustomerData;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\GoogleTagManager\Service\CartService;
use MageOS\GoogleTagManager\Service\EventQueue;

class Gtm extends DataObject implements SectionSourceInterface
{
    private const XML_PATH_VIEW_CART_ON_MINICART = 'googletagmanager/general/view_cart_on_minicart';

    private const SESSION_KEYS = [
        EventQueue::SESSION_KEY_ADD_TO_CART,
        EventQueue::SESSION_KEY_REMOVE_FROM_CART,
        EventQueue::SESSION_KEY_UPDATE_CART,
        EventQueue::SESSION_KEY_ADD_TO_WISHLIST,
        EventQueue::SESSION_KEY_REMOVE_WISHLIST,
        EventQueue::SESSION_KEY_ADD_TO_COMPARE,
        EventQueue::SESSION_KEY_CUSTOMER_LOGIN,
        EventQueue::SESSION_KEY_CUSTOMER_SIGN_UP,
    ];

    public function __construct(
        private readonly EventQueue $eventQueue,
        private readonly SerializerInterface $serializer,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CheckoutSession $checkoutSession,
        private readonly CartService $cartService,
        array $data = []
    ) {
        parent::__construct($data);
    }

    public function getSectionData(): array
    {
        $events = [];

        foreach (self::SESSION_KEYS as $key) {
            $eventData = $this->eventQueue->dequeueSessionEvent($key);
            if ($eventData !== null) {
                $events[] = $eventData;
            }
        }

        $data = [
            'datalayer' => $this->serializer->serialize($events),
        ];
        // "view_cart When the Minicart Opens": the cart as a ready view_cart event, pushed by body.phtml on Hyvä's
        // toggle-cart event. Refreshed with the section on every cart change (etc/frontend/sections.xml).
        if ($this->scopeConfig->isSetFlag(self::XML_PATH_VIEW_CART_ON_MINICART, ScopeInterface::SCOPE_STORE)) {
            try {
                $event = $this->cartService->viewCart($this->checkoutSession->getQuote()->getAllVisibleItems());
                $data['cart_view'] = empty($event['ecommerce']) ? '' : $this->serializer->serialize($event);
            } catch (\Throwable $e) {
                $data['cart_view'] = '';
            }
        }

        return $data;
    }
}
