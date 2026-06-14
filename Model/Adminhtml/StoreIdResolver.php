<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Adminhtml;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolves and VALIDATES the store ID for the admin dashboard AJAX actions.
 *
 * v2.0.1 — previously the controllers blind-cast the raw `store` request
 * parameter to int. That allowed probing arbitrary / non-existent store IDs
 * and reading the robots.txt URL of stores outside the admin's intended
 * scope. The resolver now:
 *   - accepts only digit-strings,
 *   - verifies the store actually exists via StoreRepositoryInterface,
 * and throws a LocalizedException (safe to display) otherwise.
 */
class StoreIdResolver
{
    public function __construct(
        private readonly StoreRepositoryInterface $storeRepository,
        private readonly StoreManagerInterface    $storeManager,
    ) {}

    /**
     * @throws LocalizedException when the `store` parameter is malformed or
     *                            references a non-existent store
     */
    public function resolve(RequestInterface $request): ?int
    {
        $param = $request->getParam('store');

        if ($param !== null && $param !== '') {
            if (!is_scalar($param) || !ctype_digit((string) $param)) {
                throw new LocalizedException(__('Invalid store parameter.'));
            }

            $storeId = (int) $param;

            try {
                $this->storeRepository->getById($storeId);
            } catch (NoSuchEntityException) {
                throw new LocalizedException(__('Store with ID %1 does not exist.', $storeId));
            }

            return $storeId;
        }

        try {
            $defaultStore = $this->storeManager->getDefaultStoreView();
            return $defaultStore !== null ? (int) $defaultStore->getId() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
