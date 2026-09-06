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
 *
 * @since 4.0.0 — carries the line span it occupies in the source document so
 *                a caller can remove exactly this group and leave every other
 *                byte of the operator's file untouched.
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
     * Zero-based index of the first line of this group in the source document
     * (the first User-agent line), or null when the group was not parsed from
     * a document.
     *
     * @since 4.0.0
     */
    public ?int $startLine = null;

    /**
     * Zero-based index of the last line that belongs to this group (its last
     * directive line — trailing blank lines and comments are NOT part of it).
     *
     * @since 4.0.0
     */
    public ?int $endLine = null;

    /**
     * @param string[] $userAgents
     */
    public function __construct(public array $userAgents) {}

    /**
     * Case-insensitive match check.
     */
    public function matches(string $userAgent): bool
    {
        $needle = strtolower(trim($userAgent));
        foreach ($this->userAgents as $ua) {
            if (strtolower(trim($ua)) === $needle) {
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

    /**
     * Whether this group has a usable line span in the source document.
     *
     * @since 4.0.0
     */
    public function hasSpan(): bool
    {
        return $this->startLine !== null && $this->endLine !== null
            && $this->endLine >= $this->startLine;
    }
}
