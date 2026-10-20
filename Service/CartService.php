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
        $quoteItems = $this->buildItemList($items ?? []);
        $this->eventQueue->enqueueSessionEvent(
            EventQueue::SESSION_KEY_UPDATE_CART,
            [
                'event' => 'update_cart',
                'ga4_event' => 'update_cart',
                'ecommerce' => [
                    'currency' => $this->getCurrencyCode(),
                    'value' => $this->calculateValue($quoteItems),
                    'items' => $quoteItems,
                ],
            ]
        );
    }

    public function viewCart($items): array
    {
        $quoteItems = $this->buildItemList($items ?? []);
        if ($quoteItems === []) {
            return [
                'ecommerce' => null
            ];
        }

        return [
            'event' => 'view_cart',
            'ga4_event' => 'view_cart',
            'ecommerce' => [
                'currency' => $this->getCurrencyCode(),
                'value' => $this->calculateValue($quoteItems),
                'items' => $quoteItems,
            ],
        ];
    }

    public function beginCheckout($items): array
    {
        $quoteItems = $this->buildItemList($items ?? []);
        return [
            'event' => 'begin_checkout',
            'ga4_event' => 'begin_checkout',
            'ecommerce' => [
                'currency' => $this->getCurrencyCode(),
                'value' => $this->calculateValue($quoteItems),
                'items' => $quoteItems,
            ],
        ];
    }

    public function checkoutPurchase($order): array
    {
        $orderItems = $this->buildItemList($order->getItems() ?? [], true);
        return [
            'event' => 'purchase',
            'ga4_event' => 'purchase',
            'ecommerce' => [
                'transaction_id' => $order->getIncrementId(),
                // The order's own currency, matching the order totals below (not the store's current display currency).
                'currency' => (string) $order->getOrderCurrencyCode(),
                'tax' => (float) $order->getTaxAmount(),
                'shipping' => (float) $order->getShippingAmount(),
                'value' => (float) $order->getGrandTotal(),
                'discount' => (float) $order->getDiscountAmount(),
                'coupon' => $order->getCouponCode(),
                'items' => $orderItems,
            ],
        ];
    }

    /**
     * GA4 expects `items` to be a flat list of item objects. One entry per visible cart/order row: child rows
     * (the simple product behind a configurable, bundle options) are skipped, their parent row carries the price.
     *
     * @param iterable<\Magento\Quote\Model\Quote\Item|\Magento\Sales\Model\Order\Item> $items
     */
    private function buildItemList(iterable $items, bool $isOrder = false): array
    {
        $list = [];
        foreach ($items as $item) {
            if ($item->getParentItemId() || $item->getParentItem()) {
                continue;
            }
            $product = $item->getProduct();
            if (!$product instanceof Product) {
                continue;
            }
            $qty = (float) ($isOrder ? $item->getQtyOrdered() : $item->getQty());
            $data = $this->buildProductData($product, $qty > 0 ? $qty : 1.0);
            // The row price is what the customer actually pays (configurable/custom options), not the catalog price.
            if ((float) $item->getPrice() > 0) {
                $data['price'] = round((float) $item->getPrice(), 2);
            }
            $list[] = $data;
        }

        return $list;
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
}
