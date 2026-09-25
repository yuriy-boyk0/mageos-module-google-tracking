<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\GoogleTagManager\Service\CustomerService;

class CustomerLogout implements ObserverInterface
{
    public function __construct(
        private readonly CustomerService $customerService
    ) {}

    public function execute(Observer $observer): void
    {
        /**
         * customer_logout fires before session destruction, so we store the event
         * in a short-lived public cookie instead of the checkout session.
         *
         * @var \Magento\Customer\Model\Customer $customer
         */
        $customer = $observer->getEvent()->getCustomer();

        $this->customerService->logout($customer);
    }
}
