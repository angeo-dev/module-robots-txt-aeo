<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Backend model for per-bot Allow / Disallow path-list textareas.
 *
 * Runs on save (admin form, `bin/magento config:set`, and direct DI writes):
 *   - splits the raw string on newlines and commas
 *   - strips inline `#` comments
 *   - trims whitespace
 *   - rejects entries that look like full URLs (Allow/Disallow are paths, not URLs)
 *   - normalises leading slash (every path is anchored at /)
 *   - deduplicates
 *   - re-serialises one-path-per-line for clean display
 *
 * @since 2.0.0
 */
class PathList extends Value
{
    public function beforeSave()
    {
        $raw = (string) $this->getValue();
        if (trim($raw) === '') {
            $this->setValue('');
            return parent::beforeSave();
        }

        $items = preg_split('/[\r\n,]+/', $raw) ?: [];
        $clean = [];

        foreach ($items as $item) {
            // strip inline comment
            $hashPos = strpos($item, '#');
            if ($hashPos !== false) {
                $item = substr($item, 0, $hashPos);
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }

            // Reject full URLs — Allow/Disallow values must be site-relative paths.
            if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $item)) {
                throw new LocalizedException(__(
                    'Path "%1" looks like a full URL. Allow/Disallow values must be site-relative paths (e.g. /catalog/).',
                    $item
                ));
            }

            // Anchor to /
            if ($item[0] !== '/' && $item[0] !== '*') {
                $item = '/' . $item;
            }

            $clean[] = $item;
        }

        $clean = array_values(array_unique($clean));
        $this->setValue(implode("\n", $clean));

        return parent::beforeSave();
    }
}
