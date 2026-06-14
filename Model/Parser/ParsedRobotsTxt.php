<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Parser;

/**
 * Structured representation of a parsed robots.txt file.
 *
 * - groups: User-agent groups in original document order
 * - sitemaps: file-level Sitemap: directives (not bound to any group)
 * - topComments: pure-comment lines that appear before the first group
 * - unknownDirectives: directives we don't recognise (preserved for round-trip fidelity)
 */
class ParsedRobotsTxt
{
    /** @var UserAgentGroup[] */
    public array $groups = [];

    /** @var string[] */
    public array $sitemaps = [];

    /** @var string[] */
    public array $topComments = [];

    /** @var string[] */
    public array $unknownDirectives = [];

    /**
     * Values of top-level RSL "License:" directives (rslstandard.org).
     * Per RSL 1.0 the License directive is global — not bound to any
     * User-agent group — so it is captured at file level.
     *
     * @since 3.0.0
     * @var string[]
     */
    public array $licenses = [];

    /**
     * Find the first group declaring the given user-agent (case-insensitive).
     */
    public function findGroup(string $userAgent): ?UserAgentGroup
    {
        foreach ($this->groups as $group) {
            if ($group->matches($userAgent)) {
                return $group;
            }
        }
        return null;
    }
}
