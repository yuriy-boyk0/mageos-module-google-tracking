<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\GoogleTagManager\Service\WishlistService;

class AddToWishlist implements ObserverInterface
{
    public function __construct(
        private readonly WishlistService $wishlistService
    ) {}

    public function execute(Observer $observer): void
    {
        $items = $observer->getEvent()->getItems();
        // end() instead of array_last(): the latter only exists on PHP 8.5, and Magento 2.4.9 also supports 8.3/8.4.
        $item = is_array($items) ? end($items) : false;
        if ($item) {
            $this->wishlistService->addToWishlist($item);
        }
    }
}
