<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Block;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template\Context;
use MageOS\GoogleTagManager\Service\CartService;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;

class CheckoutTagManager extends TagManager
{
    private const XML_PATH_EXCLUDE_ZERO_VALUE = 'googletagmanager/general/exclude_zero_value';

    public function __construct(
        private readonly CartService $cartService,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CheckoutSession $checkoutSession,
        private readonly Json $serializer,
        Context $context,
        array $data = []
    ) {
        parent::__construct($context, $scopeConfig, $data);
    }

    public function getJsonCartViewEvent(): string
    {
        $quoteItems = $this->checkoutSession->getQuote()->getAllVisibleItems();
        return $this->serializer->serialize(
            $this->cartService->viewCart($quoteItems)
        );
    }

    public function getJsonCheckoutBeginEvent(): string
    {
        $quoteItems = $this->checkoutSession->getQuote()->getAllVisibleItems();
        return $this->serializer->serialize(
            $this->cartService->beginCheckout($quoteItems)
        );
    }

    /**
     * Ecommerce data (currency, value, items) shared by the checkout step events
     * add_shipping_info and add_payment_info.
     */
    public function getJsonCheckoutStepBase(): string
    {
        $quoteItems = $this->checkoutSession->getQuote()->getAllVisibleItems();
        $event = $this->cartService->beginCheckout($quoteItems);

        return $this->serializer->serialize($event['ecommerce']);
    }

    public function getJsonCheckoutPurchaseEvent(): string
    {
        $order = $this->checkoutSession->getLastRealOrder();
        // "Exclude Zero-value Orders" applies to the client-side purchase as well as to the server-side one.
        if ($order->getId()
            && (float) $order->getBaseGrandTotal() <= 0.0
            && $this->scopeConfig->isSetFlag(self::XML_PATH_EXCLUDE_ZERO_VALUE, ScopeInterface::SCOPE_STORE)
        ) {
            return 'null';
        }

        return $this->serializer->serialize(
            $this->cartService->checkoutPurchase($order)
        );
    }

}
