<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Layered navigation filter parameters (attribute codes) for crawl hygiene.
 *
 * Accepts attribute codes only: lower-case letters, digits and underscores.
 * Each one is written as "Disallow: /*?*code=" in every managed bot group.
 *
 * @since 4.1.0
 */
class QueryParamList extends Value
{
    /**
     * Normalise the list before save; reject values that are not attribute codes.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $items = preg_split('/[\r\n,]+/', (string) $this->getValue()) ?: [];
        $clean = [];

        foreach ($items as $item) {
            $item = strtolower(trim($item));
            if ($item === '') {
                continue;
            }
            if (!preg_match('/^[a-z0-9_]{1,64}$/', $item)) {
                throw new LocalizedException(__(
                    '"%1" is not an attribute code. Use letters, digits and underscores only (for example color).',
                    $item
                ));
            }
            $clean[] = $item;
        }

        $this->setValue(implode("\n", array_values(array_unique($clean))));

        return parent::beforeSave();
    }
}
