<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Wishlist\Model\Wishlist\Data\WishlistItem;
use MageOS\GoogleTagManager\Service\WishlistService;

class AddToWishlist implements ObserverInterface
{
    public function __construct(
        private readonly WishlistService $wishlistService
    ) {}

    public function execute(Observer $observer): void
    {
        $items = $observer->getEvent()->getItems();
        if (is_array($items)) {
            $item = array_last($items);
        }
        $this->wishlistService->addToWishlist($item);
    }
}
