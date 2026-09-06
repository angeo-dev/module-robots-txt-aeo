<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Parser;

/**
 * Line-by-line state machine parser for robots.txt.
 *
 * Handles the real-world cases a regex gets wrong:
 *   - multiple Allow/Disallow lines per User-agent group
 *   - Crawl-delay directives
 *   - inline comments after directive values
 *   - file-level Sitemap / License directives
 *   - multiple User-agent lines stacking before a directive group
 *   - blank lines inside a group (per RFC 9309 they do NOT terminate the
 *     group; only a new User-agent line or EOF does)
 *
 * The parser is forgiving: malformed lines are skipped, not fatal.
 *
 * Reference: RFC 9309 "Robots Exclusion Protocol"
 *
 * @since 3.0.0 — top-level License: directives (RSL 1.0) are captured in
 *                ParsedRobotsTxt::$licenses; unrecognised directives inside a
 *                group (Content-Usage, Content-Signal, …) are captured in
 *                UserAgentGroup::$extraDirectives.
 * @since 4.0.0 — the source lines are retained on the result and every group
 *                records the line span it occupies, so RobotsInjector can cut
 *                out its own groups without re-rendering (and thereby
 *                reformatting) the operator's file.
 */
class RobotsTxtParser
{
    /**
     * Hard ceiling on the number of lines parsed from one document. A
     * robots.txt served by a misbehaving upstream is untrusted input; without
     * a cap, parsing is unbounded work on an admin request.
     *
     * @since 4.0.0
     */
    public const MAX_LINES = 50000;

    /**
     * Parse robots.txt content into a structured ParsedRobotsTxt model.
     */
    public function parse(string $content): ParsedRobotsTxt
    {
        $result        = new ParsedRobotsTxt();
        $result->lines = $this->normaliseLines($content);

        /** @var UserAgentGroup|null $currentGroup */
        $currentGroup       = null;
        $pendingUserAgents  = [];    // User-agent lines collected before first directive
        $pendingStartLine   = null;  // line index of the first of those
        $expectingDirective = false; // true after seeing User-agent, before first directive

        foreach ($result->lines as $index => $rawLine) {
            $line = $this->stripInlineComment($rawLine);
            $line = trim($line);

            // Blank line — does NOT close a group per RFC 9309
            if ($line === '') {
                continue;
            }

            // Pure-comment line — preserve at top level if no group is active
            if (str_starts_with(ltrim($rawLine), '#')) {
                if ($currentGroup === null && $pendingUserAgents === []) {
                    $result->topComments[] = rtrim($rawLine);
                }
                continue;
            }

            [$directive, $value] = $this->splitDirective($line);
            if ($directive === null) {
                continue; // malformed
            }

            $directiveLower = strtolower($directive);

            switch ($directiveLower) {
                case 'user-agent':
                    // New User-agent line: either starts a new group, or extends
                    // the current pending group (multiple UAs before a directive).
                    if ($expectingDirective) {
                        $pendingUserAgents[] = $value;
                    } else {
                        if ($currentGroup !== null) {
                            $result->groups[] = $currentGroup;
                        }
                        $pendingUserAgents  = [$value];
                        $pendingStartLine   = $index;
                        $currentGroup       = null;
                        $expectingDirective = true;
                    }
                    break;

                case 'allow':
                case 'disallow':
                case 'crawl-delay':
                    if ($currentGroup === null) {
                        if (empty($pendingUserAgents)) {
                            break; // directive without User-agent — skip
                        }
                        $currentGroup            = new UserAgentGroup($pendingUserAgents);
                        $currentGroup->startLine = $pendingStartLine;
                    }
                    $expectingDirective    = false;
                    $currentGroup->endLine = $index;

                    if ($directiveLower === 'allow') {
                        $currentGroup->allow[] = $value;
                    } elseif ($directiveLower === 'disallow') {
                        $currentGroup->disallow[] = $value;
                    } elseif (is_numeric($value)) { // crawl-delay
                        $currentGroup->crawlDelay = (float) $value;
                    }
                    break;

                case 'sitemap':
                    // Sitemap is a top-level directive, not bound to a group
                    if ($value !== '') {
                        $result->sitemaps[] = $value;
                    }
                    break;

                case 'license':
                    // RSL 1.0: global License directive, never group-bound.
                    if ($value !== '') {
                        $result->licenses[] = $value;
                    }
                    break;

                default:
                    // Unrecognised directive. If we are inside (or opening) a
                    // group, it belongs to that group (Content-Usage,
                    // Content-Signal, …) and materialises the group the same
                    // way Allow/Disallow do. Otherwise it is a top-level line.
                    if ($currentGroup === null && !empty($pendingUserAgents)) {
                        $currentGroup            = new UserAgentGroup($pendingUserAgents);
                        $currentGroup->startLine = $pendingStartLine;
                    }
                    if ($currentGroup !== null) {
                        $expectingDirective              = false;
                        $currentGroup->endLine           = $index;
                        $currentGroup->extraDirectives[] = $directive . ': ' . $value;
                    } else {
                        $result->unknownDirectives[] = $line;
                    }
                    break;
            }
        }

        // Close any open group
        if ($currentGroup !== null) {
            $result->groups[] = $currentGroup;
        } elseif (!empty($pendingUserAgents)) {
            // User-agent declared but no directives — keep as empty group for fidelity
            $group            = new UserAgentGroup($pendingUserAgents);
            $group->startLine = $pendingStartLine;
            $group->endLine   = $pendingStartLine;
            $result->groups[] = $group;
        }

        return $result;
    }

    /**
     * Check whether the parsed content contains a User-agent group
     * targeting the given user-agent string (case-insensitive exact match).
     */
    public function hasUserAgent(ParsedRobotsTxt $parsed, string $userAgent): bool
    {
        $needle = strtolower(trim($userAgent));
        foreach ($parsed->groups as $group) {
            foreach ($group->userAgents as $ua) {
                if (strtolower(trim($ua)) === $needle) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Return Disallow paths from the wildcard (*) group, deduplicated.
     * Used by Replace mode to preserve existing site restrictions.
     *
     * @since 4.0.0 — a site-wide "Disallow: /" is NO LONGER dropped here.
     *                Silently discarding it turned a fully blocked site
     *                (staging, pre-launch) into a fully crawlable one.
     *                Callers decide what to do with it; see
     *                RobotsInjector::buildReplaceContent().
     *
     * @return string[]
     */
    public function getWildcardDisallows(ParsedRobotsTxt $parsed): array
    {
        $paths = [];
        foreach ($parsed->groups as $group) {
            if (!$group->matches('*')) {
                continue;
            }
            foreach ($group->disallow as $path) {
                $path = trim($path);
                if ($path !== '') {
                    $paths[] = $path;
                }
            }
        }
        return array_values(array_unique($paths));
    }

    /**
     * Whether the wildcard group blocks the whole site.
     *
     * @since 4.0.0
     */
    public function wildcardBlocksSite(ParsedRobotsTxt $parsed): bool
    {
        foreach ($this->getWildcardDisallows($parsed) as $path) {
            if ($path === '/' || $path === '/*') {
                return true;
            }
        }
        return false;
    }

    /**
     * Split content into lines, normalising CRLF/CR to LF and stripping BOM.
     *
     * @return string[]
     */
    private function normaliseLines(string $content): array
    {
        // Strip UTF-8 BOM if present
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines   = explode("\n", $content);

        if (count($lines) > self::MAX_LINES) {
            $lines = array_slice($lines, 0, self::MAX_LINES);
        }

        return $lines;
    }

    /**
     * Remove an inline comment from a line (everything after an unescaped #).
     * A pure-comment line is returned unchanged; the caller handles those.
     */
    private function stripInlineComment(string $line): string
    {
        $hashPos = strpos($line, '#');
        if ($hashPos === false) {
            return $line;
        }
        if (trim(substr($line, 0, $hashPos)) === '') {
            return $line;
        }
        return substr($line, 0, $hashPos);
    }

    /**
     * Split a directive line into [name, value]. Returns [null, ''] on malformed input.
     *
     * @return array{0: string|null, 1: string}
     */
    private function splitDirective(string $line): array
    {
        $colonPos = strpos($line, ':');
        if ($colonPos === false || $colonPos === 0) {
            return [null, ''];
        }
        $name  = trim(substr($line, 0, $colonPos));
        $value = trim(substr($line, $colonPos + 1));
        return [$name, $value];
    }
}
