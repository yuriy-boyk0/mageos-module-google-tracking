<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Model\Config\Source;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * All customer groups including NOT LOGGED IN (id 0).
 */
class CustomerGroups implements OptionSourceInterface
{
    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {}

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->groupRepository->getList($this->searchCriteriaBuilder->create())->getItems() as $group) {
            $options[] = ['value' => (string) $group->getId(), 'label' => $group->getCode()];
        }

        return $options;
    }
}
