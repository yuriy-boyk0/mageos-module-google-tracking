<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Block;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template\Context;
use MageOS\GoogleTagManager\Service\CartService;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Serialize\Serializer\Json;

class CheckoutTagManager extends TagManager
{
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
        $quoteItems = $this->checkoutSession->getQuote()->getItems();
        return $this->serializer->serialize(
            $this->cartService->viewCart($quoteItems)
        );
    }

    public function getJsonCheckoutBeginEvent(): string
    {
        $quoteItems = $this->checkoutSession->getQuote()->getItems();
        return $this->serializer->serialize(
            $this->cartService->beginCheckout($quoteItems)
        );
    }

    public function getJsonCheckoutPurchaseEvent(): string
    {
        $order = $this->checkoutSession->getLastRealOrder();
        return $this->serializer->serialize(
            $this->cartService->checkoutPurchase($order)
        );
    }

}
