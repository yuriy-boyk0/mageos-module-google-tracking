<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\DataLayer;

/**
 * `page_type` pushed on every page, from Magento's full action name (route_controller_action).
 */
class PageTypeResolver
{
    private const MAP = [
        'cms_index_index' => 'home',
        'cms_page_view' => 'cms',
        'catalog_category_view' => 'category',
        'catalog_product_view' => 'product',
        'catalogsearch_result_index' => 'search',
        'catalogsearch_advanced_result' => 'search',
        'checkout_cart_index' => 'cart',
        'checkout_index_index' => 'checkout',
        'hyva_checkout_index_index' => 'checkout',
        'checkout_onepage_success' => 'success',
        'multishipping_checkout_success' => 'success',
        'cms_noroute_index' => '404',
        'catalog_product_compare_index' => 'compare',
        'wishlist_index_index' => 'wishlist',
    ];

    private const PREFIXES = [
        'customer_account_' => 'account',
        'sales_order_' => 'account',
        'wishlist_' => 'account',
        'checkout_' => 'checkout',
    ];

    public function resolve(string $fullActionName): string
    {
        $name = strtolower($fullActionName);
        if (isset(self::MAP[$name])) {
            return self::MAP[$name];
        }
        foreach (self::PREFIXES as $prefix => $type) {
            if (str_starts_with($name, $prefix)) {
                return $type;
            }
        }

        return 'other';
    }
}
