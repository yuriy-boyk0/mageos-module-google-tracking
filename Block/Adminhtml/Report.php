<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use MageOS\GoogleTagManager\Model\ReportDataProvider;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\ReportSummary;

class Report extends Template
{
    public const DAYS = 30;

    public function __construct(
        Context $context,
        private readonly ReportDataProvider $dataProvider,
        private readonly ReportSummary $summary,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getRows(): array
    {
        $since = gmdate('Y-m-d', time() - (self::DAYS - 1) * 86400);

        return $this->summary->build(
            $this->dataProvider->ordersByDay($since),
            $this->dataProvider->eventsByOrderDay($since)
        );
    }
}
