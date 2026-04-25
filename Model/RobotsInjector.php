<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model;

/**
 * Core injection logic for AI bot rules.
 *
 * Design principles:
 * - In INJECT mode: existing robots.txt content is fully preserved.
 *   AI bot rules are prepended ONLY if not already present.
 * - In REPLACE mode: a fresh robots.txt is generated with AI rules + custom or safe Magento defaults.
 * - Idempotent: running inject twice produces the same result as running it once.
 * - No DB writes: injection happens at response time via plugin, not stored in config.
 */
class RobotsInjector
{
    public function __construct(
        private readonly Config $config
    ) {}

    /**
     * Main entry point. Called by the plugin with the current robots.txt content.
     */
    public function process(string $existingContent): string
    {
        if (!$this->config->isEnabled()) {
            return $existingContent;
        }

        $enabledBots = $this->config->getEnabledBots();
        if (empty($enabledBots)) {
            return $existingContent;
        }

        if ($this->config->getMode() === Config::MODE_REPLACE) {
            return $this->buildReplaceContent($enabledBots, $existingContent);
        }

        return $this->buildInjectContent($enabledBots, $existingContent);
    }

    /**
     * INJECT MODE
     *
     * Prepends AI bot Allow rules to existing content.
     * If our managed block already exists, it is updated in-place.
     * Strips any pre-existing standalone entries for our bots to prevent duplicates.
     *
     * Result structure:
     *   # Angeo AEO — AI Crawler Rules (auto-generated, do not edit this block)
     *   User-agent: OAI-SearchBot
     *   Allow: /
     *   ...
     *   # End Angeo AEO block
     *
     *   <existing robots.txt content>
     */
    private function buildInjectContent(array $enabledBots, string $existingContent): string
    {
        if ($this->hasAngeoBlock($existingContent)) {
            return $this->updateAngeoBlock($enabledBots, $existingContent);
        }

        $block   = $this->buildAngeoBlock($enabledBots);
        $cleaned = $this->removeExistingBotEntries($enabledBots, $existingContent);

        return $block . "\n" . ltrim($cleaned);
    }

    /**
     * REPLACE MODE
     *
     * Generates a complete robots.txt.
     *
     * Priority order for the wildcard block body:
     *   1. admin-configured custom_content (textarea field) — used as-is.
     *   2. Disallow rules extracted from the existing live robots.txt.
     *   3. Safe Magento defaults (fallback when nothing else is available).
     */
    private function buildReplaceContent(array $enabledBots, string $existingContent): string
    {
        $block = $this->buildAngeoBlock($enabledBots);

        $customContent = trim($this->config->getCustomContent());
        if ($customContent !== '') {
            return $block . "\n" . $customContent . "\n";
        }

        $wildcard  = "\n# Default rules\n";
        $wildcard .= "User-agent: *\n";

        $custom = $this->extractCustomDisallows($existingContent);
        if (!empty($custom)) {
            foreach ($custom as $disallow) {
                $wildcard .= "Disallow: " . $disallow . "\n";
            }
        } else {
            $wildcard .= "Disallow: /checkout/\n";
            $wildcard .= "Disallow: /customer/\n";
            $wildcard .= "Disallow: /catalog/product_compare/\n";
            $wildcard .= "Disallow: /catalogsearch/\n";
            $wildcard .= "Disallow: /search/\n";
        }

        $wildcard .= "Allow: /\n";

        return $block . $wildcard;
    }

    /**
     * Build the Angeo-managed block of AI bot rules.
     */
    private function buildAngeoBlock(array $enabledBots): string
    {
        $lines   = [];
        $lines[] = '# Angeo AEO — AI Crawler Rules';
        $lines[] = '# https://angeo.dev | module-robots-txt-aeo';
        $lines[] = '# Do not edit this block manually — manage via Stores > Config > Angeo > Robots.txt AEO';
        $lines[] = '';

        foreach ($enabledBots as $bot) {
            $lines[] = 'User-agent: ' . $bot['user_agent'];
            $lines[] = 'Allow: /';
            $lines[] = '';
        }

        $lines[] = '# End Angeo AEO block';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Check whether our managed block already exists in the content.
     */
    private function hasAngeoBlock(string $content): bool
    {
        return str_contains($content, '# Angeo AEO — AI Crawler Rules');
    }

    /**
     * Replace the existing Angeo block with a freshly generated one.
     * Everything outside the block is untouched.
     */
    private function updateAngeoBlock(array $enabledBots, string $content): string
    {
        $newBlock = $this->buildAngeoBlock($enabledBots);
        $pattern  = '/# Angeo AEO — AI Crawler Rules.*?# End Angeo AEO block\n?/s';

        return preg_replace($pattern, $newBlock, $content) ?? $content;
    }

    /**
     * Remove any existing standalone User-agent entries for our bots
     * to prevent duplicates when injecting for the first time.
     *
     * Only removes blocks where the User-agent matches exactly one of our bots
     * and is immediately followed by Allow: / or Disallow: /.
     * Leaves wildcard blocks and unrelated bot blocks untouched.
     */
    private function removeExistingBotEntries(array $enabledBots, string $content): string
    {
        foreach ($enabledBots as $bot) {
            $ua      = preg_quote($bot['user_agent'], '/');
            $pattern = '/^User-agent:\s*' . $ua . '\s*\n(?:Allow|Disallow):[^\n]*\n?/im';
            $content = preg_replace($pattern, '', $content) ?? $content;
        }

        // Clean up multiple consecutive blank lines left behind
        return preg_replace('/\n{3,}/', "\n\n", $content) ?? $content;
    }

    /**
     * Extract Disallow paths from wildcard User-agent: * block in existing content.
     * Used by REPLACE mode to preserve custom security rules when custom_content is not set.
     *
     * @return string[]
     */
    private function extractCustomDisallows(string $content): array
    {
        $disallows  = [];
        $inWildcard = false;

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);

            if (strcasecmp($line, 'User-agent: *') === 0) {
                $inWildcard = true;
                continue;
            }

            if ($inWildcard && str_starts_with(strtolower($line), 'user-agent:')) {
                $inWildcard = false;
                continue;
            }

            if ($inWildcard && str_starts_with(strtolower($line), 'disallow:')) {
                $path = trim(substr($line, 9));
                if ($path !== '' && $path !== '/') {
                    $disallows[] = $path;
                }
            }
        }

        return array_unique($disallows);
    }

    /**
     * Generate a preview of the final robots.txt without applying it.
     * Used by CLI preview command and admin block.
     */
    public function preview(string $existingContent): string
    {
        return $this->process($existingContent);
    }

    /**
     * Validate that a given robots.txt content already contains all required bot entries.
     *
     * @return array{missing: string[], present: string[]}
     */
    public function validate(string $content): array
    {
        $result = ['missing' => [], 'present' => []];

        foreach ($this->config->getEnabledBots() as $bot) {
            $ua      = $bot['user_agent'];
            $pattern = '/^User-agent:\s*' . preg_quote($ua, '/') . '\s*$/im';

            if (preg_match($pattern, $content)) {
                $result['present'][] = $ua;
            } else {
                $result['missing'][] = $ua;
            }
        }

        return $result;
    }
}
