<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Helper;

use MageOS\GoogleTagManager\Service\JsonConfigReader;

class Template
{
    public function __construct(
        private readonly JsonConfigReader $jsonConfigReader
    ) {}
    public function getBuildInVar(): string
    {
        return $this->jsonConfigReader->getBuildInVar();
    }

    public function getTags(): string
    {
        return $this->jsonConfigReader->getTags();
    }

    public function getTriggers(): string
    {
        return $this->jsonConfigReader->getTriggers();
    }

    public function getVariables(): string
    {
        return $this->jsonConfigReader->getVariables();
    }
}
