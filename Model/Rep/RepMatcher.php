<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Rep;

use Angeo\RobotsTxtAeo\Model\Parser\ParsedRobotsTxt;
use Angeo\RobotsTxtAeo\Model\Parser\UserAgentGroup;

/**
 * RFC 9309 Robots Exclusion Protocol evaluation engine.
 *
 * Implements the normative matching semantics of RFC 9309 §2.2 that decide
 * whether a given crawler may fetch a given path — semantics that simple
 * "is the User-agent group present" checks cannot answer:
 *
 *  - Group selection (§2.2.1): a crawler obeys ONLY the merged set of groups
 *    whose user-agent line matches its product token exactly
 *    (case-insensitive). If no group matches, it obeys the merged "*" groups.
 *    If there is no "*" group either, access is allowed.
 *  - Rule precedence (§2.2.2): among all Allow/Disallow rules in the selected
 *    merged group that match the URL path, the rule with the LONGEST pattern
 *    (measured in bytes) wins. On equal length, Allow beats Disallow. The
 *    order of rules in the file carries no semantics.
 *  - Pattern syntax: "*" matches any sequence of characters; "$" anchors the
 *    end of the URL. Path matching is case-sensitive. An empty Disallow value
 *    imposes no restriction. A URL with no matching rule is allowed.
 *
 * @since 3.0.0
 */
class RepMatcher
{
    /**
     * Evaluate whether $productToken may fetch $path under $parsed.
     */
    public function isAllowed(ParsedRobotsTxt $parsed, string $productToken, string $path): AccessDecision
    {
        $path = $this->normalisePath($path);

        [$rules, $source] = $this->collectRules($parsed, $productToken);

        if ($source === AccessDecision::SOURCE_DEFAULT) {
            return new AccessDecision(true, AccessDecision::SOURCE_DEFAULT);
        }

        $best = null; // ['allow' => bool, 'pattern' => string, 'len' => int]

        foreach ($rules as [$isAllow, $pattern]) {
            $pattern = trim($pattern);

            // Empty Disallow imposes no restriction; empty Allow matches nothing useful.
            if ($pattern === '') {
                continue;
            }

            if (!$this->patternMatches($pattern, $path)) {
                continue;
            }

            $len = strlen($pattern);
            if (
                $best === null
                || $len > $best['len']
                || ($len === $best['len'] && $isAllow && !$best['allow']) // tie: Allow wins
            ) {
                $best = ['allow' => $isAllow, 'pattern' => $pattern, 'len' => $len];
            }
        }

        if ($best === null) {
            // No rule matched — allowed by default (RFC 9309 §2.2.2).
            return new AccessDecision(true, $source);
        }

        return new AccessDecision(
            $best['allow'],
            $source,
            ($best['allow'] ? 'Allow: ' : 'Disallow: ') . $best['pattern']
        );
    }

    /**
     * Select and merge the groups applicable to the product token.
     *
     * @return array{0: array<array{0: bool, 1: string}>, 1: string}
     *         [rules as [isAllow, pattern] pairs, decision source]
     */
    private function collectRules(ParsedRobotsTxt $parsed, string $productToken): array
    {
        $exact = $this->mergeMatchingGroups($parsed, $productToken);
        if ($exact !== null) {
            return [$exact, AccessDecision::SOURCE_EXACT];
        }

        $wildcard = $this->mergeMatchingGroups($parsed, '*');
        if ($wildcard !== null) {
            return [$wildcard, AccessDecision::SOURCE_WILDCARD];
        }

        return [[], AccessDecision::SOURCE_DEFAULT];
    }

    /**
     * Merge all groups declaring the given token (RFC 9309 Figure 2 — groups
     * with the same product token are treated as one merged group).
     *
     * @return array<array{0: bool, 1: string}>|null null when no group matches
     */
    private function mergeMatchingGroups(ParsedRobotsTxt $parsed, string $token): ?array
    {
        $rules   = [];
        $matched = false;

        foreach ($parsed->groups as $group) {
            if (!$this->groupMatchesToken($group, $token)) {
                continue;
            }
            $matched = true;
            foreach ($group->allow as $pattern) {
                $rules[] = [true, $pattern];
            }
            foreach ($group->disallow as $pattern) {
                $rules[] = [false, $pattern];
            }
        }

        return $matched ? $rules : null;
    }

    private function groupMatchesToken(UserAgentGroup $group, string $token): bool
    {
        $needle = strtolower(trim($token));
        foreach ($group->userAgents as $ua) {
            if (strtolower(trim($ua)) === $needle) {
                return true;
            }
        }
        return false;
    }

    /**
     * RFC 9309 pattern match: literal byte comparison, "*" = any sequence,
     * trailing "$" = end-of-URL anchor. Case-sensitive.
     */
    private function patternMatches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }

        // Build a regex: escape everything, then expand the escaped "*".
        $regex = preg_quote($pattern, '#');
        $regex = str_replace('\*', '.*', $regex);
        $regex = '#^' . $regex . ($anchored ? '$' : '') . '#';

        return (bool) preg_match($regex, $path);
    }

    /**
     * Paths are matched from the URL path component; ensure a leading slash
     * and leave case untouched (path matching is case-sensitive per RFC 9309).
     */
    private function normalisePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        return $path;
    }
}
