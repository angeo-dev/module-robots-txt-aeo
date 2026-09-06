<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Config\Backend;

use Angeo\RobotsTxtAeo\Model\Sanitizer\RobotsLineSanitizer;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Backend model for the REPLACE-mode custom robots.txt textarea.
 *
 * Until 4.0.0 this field had no backend model at all: whatever was typed went
 * into a public file verbatim. It is admin-controlled input, so this is
 * defence in depth rather than a privilege boundary — but robots.txt decides
 * whether a shop is crawled, and a stray control character or a 5 MB paste
 * should not be able to break it.
 *
 * Enforced on save: control characters removed, line endings normalised, size
 * and line count capped, and a site-wide "Disallow: /" refused with an
 * explanation instead of being silently published.
 *
 * @since 4.0.0
 */
class CustomContent extends Value
{
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly RobotsLineSanitizer $sanitizer,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $raw = (string) $this->getValue();

        if (trim($raw) === '') {
            $this->setValue('');
            return parent::beforeSave();
        }

        if (strlen($raw) > RobotsLineSanitizer::MAX_CUSTOM_BYTES) {
            throw new LocalizedException(__(
                'Custom robots.txt content is limited to %1 KB.',
                (int) (RobotsLineSanitizer::MAX_CUSTOM_BYTES / 1024)
            ));
        }

        $clean = $this->sanitizer->block($raw);

        if ($this->blocksWholeSite($clean)) {
            throw new LocalizedException(__(
                'This content blocks the whole site (User-agent: * with Disallow: /). '
                . 'If that is intentional, disable this module for the store view instead — '
                . 'a module that also publishes Allow rules for AI crawlers would send '
                . 'contradictory instructions.'
            ));
        }

        $this->setValue($clean);

        return parent::beforeSave();
    }

    /**
     * A wildcard group that disallows everything. Deliberately simple: this is
     * a save-time sanity check, not a second robots.txt evaluator.
     */
    private function blocksWholeSite(string $content): bool
    {
        $inWildcard = false;

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^user-agent\s*:\s*(.+)$/i', $line, $match)) {
                $inWildcard = trim($match[1]) === '*';
                continue;
            }

            if ($inWildcard && preg_match('/^disallow\s*:\s*(\/\*?)\s*$/i', $line)) {
                return true;
            }
        }

        return false;
    }
}
