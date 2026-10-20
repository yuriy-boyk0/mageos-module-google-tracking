<?php
namespace MageOS\GoogleTagManager\Controller\Adminhtml\Export;

use Exception;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\GoogleTagManager\Service\Export;
use Magento\Framework\Controller\ResultFactory;

class Index extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_GoogleTagManager::configuration';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Export $exportService,
        Context $context
    ) {
        parent::__construct($context);
    }

    /**
     * @return ResponseInterface|ResultInterface|void
     */
    public function execute()
    {
        $result  = ['status' => false];
        $store   = $this->getRequest()->getParam('store');
        $website = $this->getRequest()->getParam('website');

        if ($website) {
            $isEnable = $this->scopeConfig->isSetFlag(
                'googletagmanager/general/enabled',
                $website,
                ScopeInterface::SCOPE_WEBSITES
            );
        } elseif ($store) {
            $isEnable = $this->scopeConfig->isSetFlag(
                'googletagmanager/general/enabled',
                $store,
                ScopeInterface::SCOPE_STORES
            );
        } else {
            $isEnable = $this->scopeConfig->isSetFlag(
                'googletagmanager/general/enabled'
            );
        }

        if (!$isEnable) {
            $result = [
                'status'  => false,
                'message' => __('Module not enabled')
            ];
        } else {
            try {
                $content = $this->exportService->generateLiquidTemplate();
                if (!$content) {
                    $result = [
                        'status'  => false,
                        'message' => __('No information to export')
                    ];
                } else {
                    $content = str_replace('\{\{', '{{', $content);
                    $content = str_replace('\}\}', '}}', $content);

                    $this->exportService->createFile('mage-os_gtm_configuration.json', $content);

                    $result = [
                        'status'    => true,
                        'file_name' => 'mage-os_gtm_configuration.json'
                    ];
                }
            } catch (Exception $e) {
                $result['message'] = __('Something wrong exporting configuration file');
            }
        }

        return $this->resultFactory->create(ResultFactory::TYPE_JSON)->setData($result);
    }
}
