<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\GoogleTagManager\Service\MeasurementProtocol\QueueStorageInterface;

class EventType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => QueueStorageInterface::EVENT_PURCHASE, 'label' => __('Purchase')],
            ['value' => QueueStorageInterface::EVENT_REFUND, 'label' => __('Refund')],
            ['value' => QueueStorageInterface::EVENT_CANCEL, 'label' => __('Cancellation')],
        ];
    }
}
