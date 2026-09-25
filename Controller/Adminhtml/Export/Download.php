<?php
namespace MageOS\GoogleTagManager\Controller\Adminhtml\Export;

use Exception;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Serialize\Serializer\Json;

class Download extends Action
{
    public function __construct(
        private readonly FileFactory $fileFactory,
        private readonly Json $serializer,
        Context $context
    ) {
        parent::__construct($context);
    }

    /**
     * @return ResponseInterface|ResultInterface
     */
    public function execute()
    {
        $fileName = 'mage-os_gtm_configuration.json';
        try {
            return $this->fileFactory->create(
                $fileName,
                [
                    'type' => 'filename',
                    'value' => 'gtm' . '/' . $fileName
                ],
                DirectoryList::VAR_DIR
            );
        } catch (Exception $e) {
            $result['status'] = false;
            return $this->getResponse()->representJson(
                $this->serializer->serialize($result)
            );
        }
    }
}
