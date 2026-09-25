<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\GoogleTagManager\Service\WishlistService;

class RemoveFromWishlist implements ObserverInterface
{
    public function __construct(
        private readonly WishlistService $wishlistService
    ) {}

    public function execute(Observer $observer): void
    {
        $item = $observer->getEvent()->getItem()
            ?? $observer->getEvent()->getObject();

        if ($item === null) {
            return;
        }
        $this->wishlistService->removeFromWishlist($item);
    }
}
