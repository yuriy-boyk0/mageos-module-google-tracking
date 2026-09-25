<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\GoogleTagManager\Service\CartService;

class AddToCart implements ObserverInterface
{
    public function __construct(
        private readonly CartService $cartService
    ) {}

    public function execute(Observer $observer): void
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $observer->getEvent()->getProduct();
        $qty     = (float) ($observer->getEvent()->getRequest()->getParam('qty') ?: 1);
        $this->cartService->addToCart($product, $qty);
    }
}
