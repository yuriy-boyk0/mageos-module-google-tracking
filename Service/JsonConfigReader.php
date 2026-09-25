<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service;

use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Magento\Framework\Filesystem\Driver\File as FileDriver;

class JsonConfigReader
{
    public function __construct(
        protected ModuleDirReader $moduleDirReader,
        protected FileDriver $fileDriver,
    ) {}

    private function getModuleEtcDir() {
        return $this->moduleDirReader->getModuleDir(Dir::MODULE_ETC_DIR, 'MageOS_GoogleTagManager');
    }

    public function getBuildInVar(): string
    {
        $etcDir = $this->getModuleEtcDir();
        $filePath = $etcDir . '/' . 'build_in_var.json';
        return $this->fileDriver->fileGetContents($filePath);
    }

    public function getTags(): string
    {
        $etcDir = $this->getModuleEtcDir();
        $filePath = $etcDir . '/' . 'tags.json';
        return $this->fileDriver->fileGetContents($filePath);
    }

    public function getTriggers(): string
    {
        $etcDir = $this->getModuleEtcDir();
        $filePath = $etcDir . '/' . 'triggers.json';
        return $this->fileDriver->fileGetContents($filePath);
    }

    public function getVariables(): string
    {
        $etcDir = $this->getModuleEtcDir();
        $filePath = $etcDir . '/' . 'variables.json';
        return $this->fileDriver->fileGetContents($filePath);
    }
}
