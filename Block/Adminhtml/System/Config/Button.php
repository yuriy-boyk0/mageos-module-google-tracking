<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class Button extends Field
{
    protected $_template = 'system/export-button.phtml';

    public function render(AbstractElement $element): string
    {
        $element->unsScope();

        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $originalData = $element->getOriginalData();
        $this->addData([
            'button_label' => $originalData['button_label'],
            'button_url' => $this->getUrl(
                $originalData['button_url'],
                ['_current' => true]
            ),
            'html_id' => $element->getHtmlId(),
        ]);

        return $this->_toHtml();
    }

    public function getDownloadUrl(): string
    {
        return $this->getUrl('gtm/export/download');
    }
}
