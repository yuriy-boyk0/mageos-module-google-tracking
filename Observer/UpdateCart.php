<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\GoogleTagManager\Service\CartService;

class UpdateCart implements ObserverInterface
{
    public function __construct(
        private readonly CartService $cartService
    ) {}

    public function execute(Observer $observer): void
    {
        $cart = $observer->getEvent()->getCart();
        if ($cart) {
            $this->cartService->updateCart($cart->getItems());
        }
    }
}
