<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\GoogleTagManager\Service\CustomerService;

class CustomerLogin implements ObserverInterface
{
    public function __construct(
        private readonly CustomerService $customerService
    ) {}

    public function execute(Observer $observer): void
    {
        /** @var \Magento\Customer\Model\Customer $customer */
        $customer = $observer->getEvent()->getCustomer();

        $this->customerService->login($customer);
    }
}
