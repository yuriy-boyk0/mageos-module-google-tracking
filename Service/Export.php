<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service;

use Liquid\Template;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Io\File;
use MageOS\GoogleTagManager\Helper\Template as TemplateHelper;
use MageOS\GoogleTagManager\Helper\LiquidFilters;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;


class Export
{
    const CONFIG_MODULE_PATH = 'googletagmanager';
    const PROFILE_FILE_PATH  = 'gtm';

    public function __construct(
        private readonly LiquidFilters $liquidFilters,
        private readonly TemplateHelper $template,
        private readonly File $file,
        private readonly RequestInterface $request,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DirectoryList $directory
    ) {}

    /**
     * @return bool
     */
    public function checkInfo()
    {
        $ga4 = $this->scopeConfig->getValue(static::CONFIG_MODULE_PATH . '/general/ga4_measurement_id');

        if (!$ga4) {
            return false;
        }

        return true;
    }

    public function generateLiquidTemplate()
    {
        if (!$this->checkInfo()) {
            return false;
        }
        $config = [
            'ga4' => [
                'measurementId' => $this->scopeConfig->getValue(static::CONFIG_MODULE_PATH . '/general/ga4_measurement_id')
            ]
        ];
        $template = $this->prepareTemplate();

        return $template->render([
            'config' => $config
        ]);
    }

    public function prepareTemplate()
    {
        $template = new Template;
        $filtersMethods = $this->liquidFilters->getFiltersMethods();
        $template->registerFilter($this->liquidFilters);

        $exportTags = $this->template->getTags();
        $exportTrigger = $this->template->getTriggers();
        $exportVar = $this->template->getVariables();
        $buildInVar = $this->template->getBuildInVar();

        $templateHtml = '{
            "exportFormatVersion": 2,
            "containerVersion": {
                "container": {},
                "tag": ' . $exportTags . ',
                "trigger": ' . $exportTrigger . ',
                "variable": ' . $exportVar . ',
                "builtInVariable": ' . $buildInVar . '
            }
        }';

        $templateHtml = str_replace('}}', "| replacePlaceholder: '', ''}}", $templateHtml);
        array_push($filtersMethods, 'replacePlaceholder');

        $template->parse($templateHtml, $filtersMethods);

        return $template;
    }

    /**
     * @param $fileName
     * @param $content
     *
     * @throws LocalizedException
     */
    public function createFile($fileName, $content)
    {
        $filePath = $this->directory->getRoot() .'/' . DirectoryList::VAR_DIR . '/' . self::PROFILE_FILE_PATH;
        if (!$this->file->fileExists($filePath, false)) {
            $this->file->mkdir($filePath);
        }
        $fileUrl = $filePath . '/' . $fileName;
        $this->file->write($fileUrl, $content, 0777);
    }
}
