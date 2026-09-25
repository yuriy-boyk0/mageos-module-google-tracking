<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Wishlist\Model\Item as WishlistItem;

class WishlistService extends AbstractGtmService
{
    public function __construct(
        StoreManagerInterface $storeManager,
        CategoryRepositoryInterface $categoryRepository,
        private readonly EventQueue $eventQueue
    ) {
        parent::__construct($storeManager, $categoryRepository);
    }

    public function addToWishlist(WishlistItem $wishlistItem): void
    {
        $qty = (float) ($wishlistItem->getQty() ?: 1);
        $items = [$this->buildProductData($wishlistItem->getProduct(), $qty)];
        $this->eventQueue->enqueueSessionEvent(
            EventQueue::SESSION_KEY_ADD_TO_WISHLIST,
            [
                'event'     => 'add_to_wishlist',
                'ecommerce' => [
                    'currency' => $this->getCurrencyCode(),
                    'value'    => (float) ($items[0]['price'] ?? 0.0) * $qty,
                    'items'    => $items,
                ],
            ]
        );
    }

    public function removeFromWishlist(WishlistItem $wishlistItem): void
    {
        $qty = (float) ($wishlistItem->getQty() ?: 1);
        $items = [$this->buildProductData($wishlistItem->getProduct(), $qty)];
        $this->eventQueue->enqueueSessionEvent(
            EventQueue::SESSION_KEY_REMOVE_WISHLIST,
            [
                'event'     => 'remove_from_wishlist',
                'ecommerce' => [
                    'currency' => $this->getCurrencyCode(),
                    'value'    => (float) ($items[0]['price'] ?? 0.0) * $qty,
                    'items'    => $items,
                ],
            ]
        );
    }
}
