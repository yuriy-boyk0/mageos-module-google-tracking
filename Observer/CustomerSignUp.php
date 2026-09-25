<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\GoogleTagManager\Service\CustomerService;

class CustomerSignUp implements ObserverInterface
{
    public function __construct(
        private readonly CustomerService $customerService
    ) {}

    public function execute(Observer $observer): void
    {
        /**
         * customer_register_success passes CustomerInterface (Data model), not Customer model.
         *
         * @var \Magento\Customer\Api\Data\CustomerInterface $customer
         */
        $customer = $observer->getEvent()->getCustomer();

        $this->customerService->signUp($customer);
    }
}
