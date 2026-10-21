<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Observer;

use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\GoogleTagManager\Service\CompareService;

/**
 * catalog_product_compare_add_product
 */
class AddToCompare implements ObserverInterface
{
    public function __construct(
        private readonly CompareService $compareService
    ) {}

    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getData('product');
        if ($product instanceof Product) {
            $this->compareService->addToCompare($product);
        }
    }
}
