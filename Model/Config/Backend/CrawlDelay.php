<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Backend model for the per-bot Crawl-delay field.
 *
 * Accepts: empty, a non-negative integer, or a non-negative decimal.
 * Rejects negatives, NaN, strings.
 *
 * @since 2.0.0
 */
class CrawlDelay extends Value
{
    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $raw = trim((string) $this->getValue());
        if ($raw === '') {
            $this->setValue('');
            return parent::beforeSave();
        }

        if (!is_numeric($raw)) {
            throw new LocalizedException(__('Crawl-delay must be a non-negative number, got "%1".', $raw));
        }

        $value = (float) $raw;
        if ($value < 0) {
            throw new LocalizedException(__('Crawl-delay must be ≥ 0, got %1.', $raw));
        }

        // Store as int when whole, otherwise as decimal string
        $this->setValue($value == (int) $value ? (string) (int) $value : (string) $value);
        return parent::beforeSave();
    }
}
