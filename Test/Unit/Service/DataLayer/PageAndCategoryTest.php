<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Test\Unit\Service\DataLayer;

use MageOS\GoogleTagManager\Service\DataLayer\CategoryPathPicker;
use MageOS\GoogleTagManager\Service\DataLayer\CustomItemAttributes;
use MageOS\GoogleTagManager\Service\DataLayer\PageTypeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PageAndCategoryTest extends TestCase
{
    #[DataProvider('pages')]
    public function testPageType(string $action, string $type): void
    {
        $this->assertSame($type, (new PageTypeResolver())->resolve($action));
    }

    public static function pages(): array
    {
        return [
            ['cms_index_index', 'home'], ['cms_page_view', 'cms'], ['catalog_category_view', 'category'],
            ['catalog_product_view', 'product'], ['catalogsearch_result_index', 'search'], ['checkout_cart_index', 'cart'],
            ['checkout_index_index', 'checkout'], ['checkout_onepage_success', 'success'], ['customer_account_login', 'account'],
            ['sales_order_history', 'account'], ['cms_noroute_index', '404'], ['contact_index_index', 'other'],
            ['CATALOG_PRODUCT_VIEW', 'product'],
        ];
    }

    public function testCategoryPathLongestWinsAndDepthCuts(): void
    {
        $picker = new CategoryPathPicker();
        $paths = [[37], [20, 21, 23]];
        $this->assertSame([20, 21, 23], $picker->pick($paths));
        $this->assertSame([20, 21], $picker->pick($paths, [], 2));
        $this->assertSame([20], $picker->pick($paths, [], 0), 'depth at least 1');
        $this->assertSame([], $picker->pick([]));
    }

    public function testExcludedCategoriesAreRemovedAndTheNextLevelMovesUp(): void
    {
        $picker = new CategoryPathPicker();
        // "Sale" (40) is excluded: [40, 21, 23] becomes [21, 23]; [20, 21, 23, 24] stays longest
        $this->assertSame([21, 23], $picker->pick([[40, 21, 23], [37]], [40]));
        $this->assertSame([37], $picker->pick([[40], [37]], [40]));
        $this->assertSame([12, 15], $picker->parseIds(' 12, 15,abc,,12'));
    }

    public function testCustomItemAttributes(): void
    {
        $parser = new CustomItemAttributes();
        $this->assertSame(
            ['item_color' => 'color', 'item_material' => 'material'],
            $parser->parse("item_color:color\nitem_material : material")
        );
        $this->assertSame([], $parser->parse('price:price, item_brand:manufacturer, 1bad:color, google_x:color, nocolon'), 'reserved / invalid skipped');
        $six = 'a1:c1,a2:c2,a3:c3,a4:c4,a5:c5,a6:c6';
        $this->assertCount(5, $parser->parse($six));
        $this->assertSame(['item_size' => 'size'], $parser->parse('ITEM_SIZE:size, item_size:other'), 'first one wins');
    }
}
