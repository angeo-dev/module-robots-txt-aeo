<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Parser;

/**
 * One User-agent group as parsed from robots.txt.
 *
 * A group may declare multiple user-agents that share the same set of
 * Allow/Disallow/Crawl-delay directives:
 *
 *   User-agent: GPTBot
 *   User-agent: ChatGPT-User
 *   Allow: /
 *   Disallow: /admin/
 *   Crawl-delay: 5
 */
class UserAgentGroup
{
    /** @var string[] */
    public array $allow = [];

    /** @var string[] */
    public array $disallow = [];

    public ?float $crawlDelay = null;

    /**
     * Raw "Name: value" directive lines inside this group that are not
     * Allow/Disallow/Crawl-delay — e.g. Content-Usage (IETF aipref-attach),
     * Content-Signal (Cloudflare Content Signals Policy), or future
     * extensions. Preserved verbatim, in original order, for lossless
     * round-trip rendering.
     *
     * @since 3.0.0
     * @var string[]
     */
    public array $extraDirectives = [];

    /**
     * @param string[] $userAgents
     */
    public function __construct(public array $userAgents) {}

    /**
     * Case-insensitive match check.
     */
    public function matches(string $userAgent): bool
    {
        $needle = strtolower($userAgent);
        foreach ($this->userAgents as $ua) {
            if (strtolower($ua) === $needle) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the group contains any directives at all.
     */
    public function isEmpty(): bool
    {
        return empty($this->allow) && empty($this->disallow)
            && $this->crawlDelay === null && empty($this->extraDirectives);
    }
}
