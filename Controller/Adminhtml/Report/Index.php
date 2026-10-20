<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Controller\Adminhtml\Report;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_GoogleTagManager::report';

    public function execute()
    {
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('MageOS_GoogleTagManager::report');
        $page->getConfig()->getTitle()->prepend(__('GA4 Server-side Events'));

        return $page;
    }
}
