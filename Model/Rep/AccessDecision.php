<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Rep;

/**
 * Result of an RFC 9309 access evaluation for one (product token, path) pair.
 *
 * @since 3.0.0
 */
final class AccessDecision
{
    public const SOURCE_EXACT    = 'exact';    // a group named the token
    public const SOURCE_WILDCARD = 'wildcard'; // fell back to the * group
    public const SOURCE_DEFAULT  = 'default';  // no group matched — allowed

    public function __construct(
        public readonly bool    $allowed,
        public readonly string  $source,
        public readonly ?string $matchedRule = null,   // e.g. "Disallow: /private/"
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'allowed'      => $this->allowed,
            'source'       => $this->source,
            'matched_rule' => $this->matchedRule,
        ];
    }
}
