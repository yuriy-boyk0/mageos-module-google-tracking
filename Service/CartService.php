<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Store\Model\StoreManagerInterface;

class CartService extends AbstractGtmService
{
    public function __construct(
        StoreManagerInterface $storeManager,
        CategoryRepositoryInterface $categoryRepository,
        private readonly EventQueue $eventQueue
    ) {
        parent::__construct($storeManager, $categoryRepository);
    }

    public function addToCart(Product $product, float $qty): void
    {
        $items = [$this->buildProductData($product, $qty)];
        $this->eventQueue->enqueueSessionEvent(
            EventQueue::SESSION_KEY_ADD_TO_CART,
            [
                'event' => 'add_to_cart',
                'ecommerce' => [
                    'currency' => $this->getCurrencyCode(),
                    'value' => $this->calculateValue($items),
                    'items' => $items,
                ],
            ]
        );
    }

    public function removeFromCart(QuoteItem $quoteItem): void
    {
        $items = [$this->buildProductData($quoteItem->getProduct(), (float) $quoteItem->getQty())];
        $this->eventQueue->enqueueSessionEvent(
            EventQueue::SESSION_KEY_REMOVE_FROM_CART,
            [
                'event' => 'remove_from_cart',
                'ga4_event' => 'remove_from_cart',
                'ecommerce' => [
                    'currency' => $this->getCurrencyCode(),
                    'value' => $this->calculateValue($items),
                    'items' => $items,
                ],
            ]
        );
    }

    public function updateCart($items): void
    {
        $quoteItems = [];
        foreach ($items as $item) {
            if (!isset( $quoteItems[$item->getSku()])) {
                $quoteItems[$item->getSku()] = [$this->buildProductData($item->getProduct(), (float)$item->getQty())];
            }
        }
        $this->eventQueue->enqueueSessionEvent(
            EventQueue::SESSION_KEY_UPDATE_CART,
            [
                'event' => 'update_cart',
                'ga4_event' => 'update_cart',
                'ecommerce' => [
                    'currency' => $this->getCurrencyCode(),
                    'value' => $this->calculateTotalValue($quoteItems),
                    'items' => $quoteItems,
                ],
            ]
        );
    }

    public function viewCart($items): array
    {
        $quoteItems = [];
        if ($items) {
            foreach ($items as $item) {
                if (!isset($quoteItems[$item->getSku()])) {
                    $quoteItems[$item->getSku()] = [$this->buildProductData($item->getProduct(), (float)$item->getQty())];
                }
            }
            return [
                'event' => 'view_cart',
                'ga4_event' => 'view_cart',
                'ecommerce' => [
                    'currency' => $this->getCurrencyCode(),
                    'value' => $this->calculateTotalValue($quoteItems),
                    'items' => $quoteItems,
                ],
            ];
        }
        return [
            'ecommerce' => null
        ];
    }


    public function beginCheckout($items): array
    {
        $quoteItems = [];
        foreach ($items as $item) {
            if (!isset( $quoteItems[$item->getSku()])) {
                $quoteItems[$item->getSku()] = [$this->buildProductData($item->getProduct(), (float)$item->getQty())];
            }
        }
        return [
            'event' => 'begin_checkout',
            'ga4_event' => 'begin_checkout',
            'ecommerce' => [
                'currency' => $this->getCurrencyCode(),
                'value' => $this->calculateTotalValue($quoteItems),
                'items' => $quoteItems,
            ],
        ];
    }

    public function checkoutPurchase($order): array
    {
        $orderItems = [];
        foreach ($order->getItems() as $item) {
            if (!isset($orderItems[$item->getSku()])) {
                $orderItems[$item->getSku()] = [$this->buildProductData($item->getProduct(), (float)$item->getQty())];
            }
        }
        return [
            'event' => 'checkout_success',
            'ga4_event' => 'checkout_success',
            'ecommerce' => [
                'transaction_id' => $order->getIncrementId(),
                'currency' => $this->getCurrencyCode(),
                'tax' => $order->getTaxAmount(),
                'shipping' => $order->getShippingAmount(),
                'value' => $order->getGrandTotal(),
                'discount' => $order->getDiscountAmount(),
                'coupon' => $order->getCouponCode(),
                'items' => $orderItems,
            ],
        ];
    }

    private function calculateValue(array $items): float
    {
        return round(
            array_sum(
                array_map(
                    static fn(array $item) => ($item['price'] ?? 0.0) * ($item['quantity'] ?? 1.0),
                    $items
                )
            ), 2
        );
    }

    private function calculateTotalValue(array $items): float
    {
        $totalValue = 0;
        foreach ($items as $item) {
            $totalValue+= $this->calculateValue($item);
        }
        return $totalValue;
    }
}
