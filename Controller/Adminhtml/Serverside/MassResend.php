<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Controller\Adminhtml\Serverside;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Ui\Component\MassAction\Filter;
use MageOS\GoogleTagManager\Model\PurchaseResender;

class MassResend extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_GoogleTagManager::server_side_resend';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly PurchaseResender $resender
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $orders = $this->filter->getCollection($this->collectionFactory->create());
            $result = $this->resender->resend($orders, time());

            if ($result['queued'] > 0) {
                $this->messageManager->addSuccessMessage(
                    __('%1 order(s) queued for the GA4 server-side purchase event. Cron sends them within a minute.', $result['queued'])
                );
            }
            foreach ($result['ignored'] as $reason => $count) {
                $this->messageManager->addNoticeMessage(__('%1 order(s) not queued: %2', $count, $reason));
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not queue the orders: %1', $e->getMessage()));
        }

        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('sales/order/index');
    }
}
