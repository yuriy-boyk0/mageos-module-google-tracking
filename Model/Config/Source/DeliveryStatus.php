<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\QueueStorageInterface;

class DeliveryStatus implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => QueueStorageInterface::STATUS_SENT, 'label' => __('Sent')],
            ['value' => QueueStorageInterface::STATUS_PENDING, 'label' => __('Pending')],
            ['value' => QueueStorageInterface::STATUS_PROCESSING, 'label' => __('Processing')],
            ['value' => QueueStorageInterface::STATUS_FAILED, 'label' => __('Failed')],
            ['value' => QueueStorageInterface::STATUS_EXPIRED, 'label' => __('Expired (too old)')],
            ['value' => QueueStorageInterface::STATUS_SKIPPED, 'label' => __('Skipped')],
        ];
    }
}
