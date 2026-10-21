<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use MageOS\GoogleTagManager\Service\CartService;

class AddToCart implements ObserverInterface
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly CheckoutSession $checkoutSession
    ) {}

    public function execute(Observer $observer): void
    {
        /** @var Product $product */
        $product = $observer->getEvent()->getProduct();
        $request = $observer->getEvent()->getRequest();
        if ($product->getTypeId() === 'grouped') {
            $superGroup = $request->getParam('super_group');
            $this->cartService->addGroupedToCart($product, is_array($superGroup) ? $superGroup : []);
            return;
        }
        $qty = (float) ($request->getParam('qty') ?: 1);
        $this->cartService->addToCart($product, $qty, $product->getTypeId() === 'bundle' ? $this->quoteItem($product) : null);
    }

    /**
     * The cart row of a bundle just added: its price is that of the chosen options, the catalog price only the
     * cheapest combination. The product carries the chosen options, so the quote finds its row.
     */
    private function quoteItem(Product $product): ?QuoteItem
    {
        try {
            $item = $this->checkoutSession->getQuote()->getItemByProduct($product);

            return $item instanceof QuoteItem ? $item : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
