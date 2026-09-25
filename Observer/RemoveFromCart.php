<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\GoogleTagManager\Service\CartService;

class RemoveFromCart implements ObserverInterface
{
    public function __construct(
        private readonly CartService $cartService
    ) {}

    public function execute(Observer $observer): void
    {
        /** @var \Magento\Quote\Model\Quote\Item $quoteItem */
        $quoteItem = $observer->getEvent()->getQuoteItem();
        $this->cartService->removeFromCart($quoteItem);
    }
}
