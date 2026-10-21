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
        ItemDataResolver $itemData,
        private readonly EventQueue $eventQueue
    ) {
        parent::__construct($storeManager, $categoryRepository, $itemData);
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
        // The row as it was in the cart (price the customer paid); the catalog product only as a fallback.
        $items = $this->buildItemList([$quoteItem])
            ?: [$this->buildProductData($quoteItem->getProduct(), (float) $quoteItem->getQty())];
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
                // Discounts are sent per item (positive, per unit), like the server-side purchase; GA4 has no
                // event-level discount.
                'coupon' => $order->getCouponCode(),
                'items' => $orderItems,
            ],
        ];
    }

    /**
     * GA4 expects `items` to be a flat list of item objects. One entry per visible cart/order row: child rows
     * (the simple product behind a configurable, bundle options) are skipped, their parent row carries the price.
     * Items are described exactly like the server-side purchase: item_id, item_variant, category path, and a
     * positive per-unit discount.
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
            $qty = $qty > 0 ? $qty : 1.0;
            // Same item_id / item_variant / categories as the server-side purchase (ItemDataResolver).
            $data = ($isOrder
                    ? $this->itemData->forOrderItem($item, (int) $item->getStoreId())
                    : $this->itemData->forQuoteItem($item, $this->currentStoreId()))
                + [
                    'item_name' => (string) $item->getName(),
                    'price' => $this->unitPrice($product),
                    'quantity' => $qty,
                ];
            // The row price is what the customer actually pays (configurable/custom options), not the catalog price.
            $price = $isOrder ? $this->orderItemPrice($item) : $this->quoteItemPrice($item, $qty);
            if ($price > 0) {
                $data['price'] = $price;
            }
            $discount = (float) $item->getDiscountAmount();
            if ($discount > 0) {
                $data['discount'] = round($discount / $qty, 2);
            }
            $list[] = $data;
        }

        return $list;
    }

    /**
     * Order items are already in the order currency (and excluding tax).
     */
    private function orderItemPrice($item): float
    {
        return round((float) $item->getPrice(), 2);
    }

    /**
     * Quote items keep `price` in the BASE currency; the unit price excluding tax in the quote (display) currency is
     * `converted_price`, set by the tax collector. Falls back to row total / qty, then to converting the base price.
     */
    private function quoteItemPrice($item, float $qty): float
    {
        $converted = (float) $item->getConvertedPrice();
        if ($converted > 0) {
            return round($converted, 2);
        }
        $rowTotal = (float) $item->getRowTotal();
        if ($rowTotal > 0 && $qty > 0) {
            return round($rowTotal / $qty, 2);
        }

        return (float) $item->getPrice() > 0 ? $this->toDisplayCurrency((float) $item->getPrice()) : 0.0;
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
