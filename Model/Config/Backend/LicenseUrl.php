<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Validates the RSL license file URL (licensing/rsl_license_url).
 *
 * RSL 1.0 (rslstandard.org): the License directive must carry a fully
 * qualified absolute URL; it may live on a different host than robots.txt.
 * We additionally require https:// — emitting a plaintext-HTTP licensing
 * endpoint would undermine the integrity of the license reference.
 *
 * @since 3.0.0
 */
class LicenseUrl extends Value
{
    public function beforeSave(): self
    {
        $value = trim((string) $this->getValue());

        if ($value === '') {
            $this->setValue('');
            return parent::beforeSave();
        }

        if (!filter_var($value, FILTER_VALIDATE_URL) || stripos($value, 'https://') !== 0) {
            throw new LocalizedException(
                __('RSL license URL must be an absolute https:// URL (e.g. https://example.com/license.xml).')
            );
        }

        if (preg_match('/[\r\n#]/', $value)) {
            throw new LocalizedException(__('RSL license URL must be a single line without "#" characters.'));
        }

        $this->setValue($value);
        return parent::beforeSave();
    }
}
