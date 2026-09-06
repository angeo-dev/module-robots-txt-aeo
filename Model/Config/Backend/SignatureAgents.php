<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

/**
 * Validates the operator-managed list of trusted Web Bot Auth signing origins
 * (verification/trusted_signature_agents), one per line.
 *
 * Each entry must be an absolute https origin — scheme and host, nothing else.
 * A path or a query string here would mean the value is not an origin, and an
 * origin is exactly what the Signature-Agent header is compared against.
 *
 * @since 4.0.0
 */
class SignatureAgents extends Value
{
    public const MAX_ENTRIES = 50;

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

        $origins = [];

        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $line  = rtrim($line, '/');
            $parts = parse_url($line);

            if (!is_array($parts)
                || ($parts['scheme'] ?? '') !== 'https'
                || empty($parts['host'])
                || !empty($parts['path'])
                || !empty($parts['query'])
                || !empty($parts['fragment'])
            ) {
                throw new LocalizedException(__(
                    '"%1" is not a signing origin. Use the scheme and host only, '
                    . 'for example https://chatgpt.com',
                    $line
                ));
            }

            $origin = 'https://' . strtolower((string) $parts['host']);
            if (isset($parts['port'])) {
                $origin .= ':' . (int) $parts['port'];
            }

            $origins[] = $origin;
        }

        $origins = array_values(array_unique($origins));

        if (count($origins) > self::MAX_ENTRIES) {
            throw new LocalizedException(__('At most %1 trusted signing origins can be configured.', self::MAX_ENTRIES));
        }

        $this->setValue(implode("\n", $origins));

        return parent::beforeSave();
    }
}
