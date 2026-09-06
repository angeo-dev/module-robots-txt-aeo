<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Validates the IETF aipref preference statement
 * (content_signals/ietf_preference), e.g. "train-ai=n".
 *
 * draft-ietf-aipref-attach processes the rule value up to the first CR, LF,
 * or "#" — so those characters must not appear in the stored value, or the
 * emitted robots.txt line would be truncated/corrupted. The vocabulary
 * encoding (draft-ietf-aipref-vocab §6) is a Structured-Fields dictionary in
 * printable ASCII.
 *
 * @since 3.0.0
 */
class UsagePreference extends Value
{
    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());

        if ($value === '') {
            $this->setValue('');
            return parent::beforeSave();
        }

        if (preg_match('/[\r\n#]/', $value)) {
            throw new LocalizedException(
                __('Content-Usage preference must be a single line and must not contain "#".')
            );
        }

        if (!preg_match('/^[\x20-\x7E]+$/', $value)) {
            throw new LocalizedException(
                __('Content-Usage preference must contain printable ASCII only (e.g. "train-ai=n").')
            );
        }

        $this->setValue($value);
        return parent::beforeSave();
    }
}
