<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Configuration reader for Angeo_RobotsTxtAeo.
 *
 * All bot definitions live here so adding a new bot in the future
 * requires only: (1) adding a row to BOTS, (2) adding a field in system.xml,
 * (3) adding a default in config.xml.
 */
class Config
{
    private const XML_PREFIX = 'angeo_robots_txt_aeo/';

    public const MODE_INJECT  = 'inject';
    public const MODE_REPLACE = 'replace';

    /**
     * Canonical bot definitions.
     *
     * key         => config field id (maps to angeo_robots_txt_aeo/bots/<key>)
     * user_agent  => exact string used in robots.txt User-agent: directive
     * label       => human-readable name shown in CLI output
     * description => one-line explanation of the bot's purpose
     * respects_robots_txt => factual accuracy note, used in CLI output only
     */
    public const BOTS = [
        'oai_searchbot' => [
            'user_agent'          => 'OAI-SearchBot',
            'label'               => 'OAI-SearchBot',
            'description'         => 'ChatGPT live search — fetches product pages for shopping queries',
            'respects_robots_txt' => true,
        ],
        'gptbot' => [
            'user_agent'          => 'GPTBot',
            'label'               => 'GPTBot',
            'description'         => 'OpenAI training crawler — block to opt out of GPT model training',
            'respects_robots_txt' => true,
        ],
        'chatgpt_user' => [
            'user_agent'          => 'ChatGPT-User',
            'label'               => 'ChatGPT-User',
            'description'         => 'ChatGPT user-triggered browsing',
            'respects_robots_txt' => true,
        ],
        'perplexitybot' => [
            'user_agent'          => 'PerplexityBot',
            'label'               => 'PerplexityBot',
            'description'         => 'Perplexity background indexer — allow to appear in Perplexity results',
            'respects_robots_txt' => true,
        ],
        'perplexity_user' => [
            'user_agent'          => 'Perplexity-User',
            'label'               => 'Perplexity-User',
            'description'         => 'Perplexity real-time fetch (does NOT respect robots.txt in practice)',
            'respects_robots_txt' => false,
        ],
        'google_extended' => [
            'user_agent'          => 'Google-Extended',
            'label'               => 'Google-Extended',
            'description'         => 'Gemini / AI Overviews — does not affect Google Search rankings',
            'respects_robots_txt' => true,
        ],
        'claudebot' => [
            'user_agent'          => 'ClaudeBot',
            'label'               => 'ClaudeBot',
            'description'         => 'Anthropic Claude citation fetcher',
            'respects_robots_txt' => true,
        ],
        'anthropic_ai' => [
            'user_agent'          => 'anthropic-ai',
            'label'               => 'anthropic-ai',
            'description'         => 'Anthropic training crawler — block to opt out of Claude model training',
            'respects_robots_txt' => true,
        ],
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_PREFIX . 'general/enabled');
    }

    public function getMode(): string
    {
        return (string) ($this->scopeConfig->getValue(self::XML_PREFIX . 'general/mode') ?? self::MODE_INJECT);
    }

    /**
     * Custom robots.txt body for Replace mode.
     * When non-empty, used as-is instead of deriving Disallow rules from the live file.
     */
    public function getCustomContent(): string
    {
        return (string) ($this->scopeConfig->getValue(self::XML_PREFIX . 'general/custom_content') ?? '');
    }

    /**
     * Returns bot definitions that are enabled in admin config.
     *
     * @return array<string, array{user_agent: string, label: string, description: string, respects_robots_txt: bool}>
     */
    public function getEnabledBots(): array
    {
        $enabled = [];
        foreach (self::BOTS as $key => $bot) {
            if ((bool) $this->scopeConfig->getValue(self::XML_PREFIX . 'bots/' . $key)) {
                $enabled[$key] = $bot;
            }
        }
        return $enabled;
    }

    /**
     * Returns all bot definitions regardless of enabled state.
     *
     * @return array<string, array{user_agent: string, label: string, description: string, respects_robots_txt: bool}>
     */
    public function getAllBots(): array
    {
        return self::BOTS;
    }
}
