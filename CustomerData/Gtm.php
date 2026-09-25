<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\CustomerData;

use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Serialize\SerializerInterface;
use MageOS\GoogleTagManager\Service\EventQueue;

class Gtm extends DataObject implements SectionSourceInterface
{
    private const SESSION_KEYS = [
        EventQueue::SESSION_KEY_ADD_TO_CART,
        EventQueue::SESSION_KEY_REMOVE_FROM_CART,
        EventQueue::SESSION_KEY_UPDATE_CART,
        EventQueue::SESSION_KEY_ADD_TO_WISHLIST,
        EventQueue::SESSION_KEY_REMOVE_WISHLIST,
        EventQueue::SESSION_KEY_CUSTOMER_LOGIN,
        EventQueue::SESSION_KEY_CUSTOMER_SIGN_UP,
    ];

    public function __construct(
        private readonly EventQueue $eventQueue,
        private readonly SerializerInterface $serializer,
        array $data = []
    ) {
        parent::__construct($data);
    }

    public function getSectionData(): array
    {
        $events = [];

        foreach (self::SESSION_KEYS as $key) {
            $eventData = $this->eventQueue->dequeueSessionEvent($key);
            if ($eventData !== null) {
                $events[] = $eventData;
            }
        }

        return [
            'datalayer' => $this->serializer->serialize($events),
        ];
    }
}
