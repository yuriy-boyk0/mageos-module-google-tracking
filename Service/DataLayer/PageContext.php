<?php
declare(strict_types=1);

namespace MageOS\GoogleTagManager\Service\DataLayer;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Store\Model\ScopeInterface;

/**
 * Visitor facts that may be rendered into full-page-cached HTML: Magento's HTTP context (customer group, logged in)
 * is part of the page cache key, so every group / login state gets its own cached copy.
 */
class PageContext
{
    public const XML_PATH_EXCLUDED_GROUPS = 'googletagmanager/general/excluded_customer_groups';

    /** @var array<int, string> */
    private array $groupNames = [];

    public function __construct(
        private readonly HttpContext $httpContext,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly PageTypeResolver $pageTypeResolver
    ) {}

    public function customerGroupId(): int
    {
        return (int) $this->httpContext->getValue(CustomerContext::CONTEXT_GROUP);
    }

    public function isLoggedIn(): bool
    {
        return (bool) $this->httpContext->getValue(CustomerContext::CONTEXT_AUTH);
    }

    public function customerGroupName(): string
    {
        $id = $this->customerGroupId();
        if (!isset($this->groupNames[$id])) {
            try {
                $this->groupNames[$id] = (string) $this->groupRepository->getById($id)->getCode();
            } catch (\Throwable $e) {
                $this->groupNames[$id] = (string) $id;
            }
        }

        return $this->groupNames[$id];
    }

    /**
     * "Don't Track Customer Groups": no GTM, no dataLayer events for these groups.
     */
    public function isCurrentGroupExcluded(): bool
    {
        return in_array($this->customerGroupId(), $this->excludedGroupIds(), true);
    }

    /**
     * @return int[]
     */
    public function excludedGroupIds(?int $storeId = null): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_PATH_EXCLUDED_GROUPS, ScopeInterface::SCOPE_STORE, $storeId);
        $ids = [];
        foreach (explode(',', $raw) as $id) {
            if (trim($id) !== '' && ctype_digit(trim($id))) {
                $ids[] = (int) trim($id);
            }
        }

        return $ids;
    }

    /**
     * Pushed before GTM loads on every page: page_type, customer_group, logged_in.
     */
    public function dataLayerContext(string $fullActionName): array
    {
        return [
            'page_type' => $this->pageTypeResolver->resolve($fullActionName),
            'customer_group' => $this->customerGroupName(),
            'logged_in' => $this->isLoggedIn() ? 'yes' : 'no',
        ];
    }
}
