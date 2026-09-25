<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Customer;

class CustomerService
{
    public function __construct(
        private readonly EventQueue $eventQueue
    ) {}
    public function login(Customer $customer): void
    {
        $this->eventQueue->enqueueSessionEvent(
            EventQueue::SESSION_KEY_CUSTOMER_LOGIN,
            [
                'event' => 'login',
                'method' => 'Magento',
                'user_id' => (string)$customer->getId(),
            ]
        );
    }
    public function signUp(CustomerInterface $customer): void
    {
        $this->eventQueue->enqueueSessionEvent(
            EventQueue::SESSION_KEY_CUSTOMER_SIGN_UP,
            [
                'event'   => 'sign_up',
                'method'  => 'Magento',
                'user_id' => (string) $customer->getId(),
            ]
        );
    }
    public function logout(Customer $customer): void
    {
        $this->eventQueue->enqueueLogoutCookie([
            'event'   => 'logout',
            'user_id' => (string) $customer->getId(),
        ]);
    }
}
