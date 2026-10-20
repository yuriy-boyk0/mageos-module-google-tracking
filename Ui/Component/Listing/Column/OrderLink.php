<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Order number linked to the order view page.
 */
class OrderLink extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly Escaper $escaper,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource)
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $name = $this->getData('name');
        // By reference on the real array: `?? []` would iterate a temporary copy and the links would be lost.
        foreach ($dataSource['data']['items'] as &$item) {
            if (empty($item['order_id'])) {
                continue;
            }
            $item[$name] = sprintf(
                '<a href="%s">%s</a>',
                $this->escaper->escapeUrl($this->urlBuilder->getUrl('sales/order/view', ['order_id' => $item['order_id']])),
                $this->escaper->escapeHtml((string) ($item[$name] ?? ''))
            );
        }
        unset($item);

        return $dataSource;
    }
}
