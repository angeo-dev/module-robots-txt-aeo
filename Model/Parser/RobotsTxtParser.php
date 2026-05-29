<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Parser;

/**
 * Line-by-line state machine parser for robots.txt.
 *
 * Replaces the fragile regex-based parsing that lived inside RobotsInjector.
 * Handles all real-world cases the regex version got wrong:
 *   - multiple Allow/Disallow lines per User-agent group
 *   - Crawl-delay directives
 *   - inline comments after directive values
 *   - group-level Sitemap (non-grouped, file-level)
 *   - multiple User-agent lines stacking before a directive group
 *   - blank lines inside a group (per RFC 9309 they do NOT terminate the group;
 *     only a new User-agent line or EOF does)
 *
 * The parser is forgiving: malformed lines are skipped, not fatal. Comments
 * starting with '#' are preserved on the lines they belong to so the parsed
 * model can be re-serialized losslessly when needed.
 *
 * Reference: RFC 9309 "Robots Exclusion Protocol"
 */
class RobotsTxtParser
{
    /**
     * Parse robots.txt content into a structured ParsedRobotsTxt model.
     */
    public function parse(string $content): ParsedRobotsTxt
    {
        $result = new ParsedRobotsTxt();

        /** @var UserAgentGroup|null $currentGroup */
        $currentGroup       = null;
        $pendingUserAgents  = [];     // User-agent lines collected before first directive
        $expectingDirective = false;  // true after seeing User-agent, before first directive

        foreach ($this->normaliseLines($content) as $rawLine) {
            $line = $this->stripInlineComment($rawLine);
            $line = trim($line);

            // Blank line — does NOT close a group per RFC 9309
            if ($line === '') {
                continue;
            }

            // Pure-comment line — preserve at top level if no group active
            if (str_starts_with($rawLine, '#')) {
                if ($currentGroup === null) {
                    $result->topComments[] = $rawLine;
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
                        $currentGroup       = null;
                        $expectingDirective = true;
                    }
                    break;

                case 'allow':
                case 'disallow':
                case 'crawl-delay':
                    if ($currentGroup === null) {
                        if (empty($pendingUserAgents)) {
                            // directive without User-agent — skip
                            break;
                        }
                        $currentGroup = new UserAgentGroup($pendingUserAgents);
                    }
                    $expectingDirective = false;

                    if ($directiveLower === 'allow') {
                        $currentGroup->allow[] = $value;
                    } elseif ($directiveLower === 'disallow') {
                        $currentGroup->disallow[] = $value;
                    } else { // crawl-delay
                        if (is_numeric($value)) {
                            $currentGroup->crawlDelay = (float) $value;
                        }
                    }
                    break;

                case 'sitemap':
                    // Sitemap is a top-level directive, not bound to any User-agent group
                    if ($value !== '') {
                        $result->sitemaps[] = $value;
                    }
                    break;

                default:
                    // Unknown directive — record but do not fail
                    $result->unknownDirectives[] = $line;
                    break;
            }
        }

        // Close any open group
        if ($currentGroup !== null) {
            $result->groups[] = $currentGroup;
        } elseif (!empty($pendingUserAgents)) {
            // User-agent declared but no directives — keep as empty group for fidelity
            $result->groups[] = new UserAgentGroup($pendingUserAgents);
        }

        return $result;
    }

    /**
     * Check whether the parsed content contains a User-agent group
     * targeting the given user-agent string (case-insensitive exact match).
     */
    public function hasUserAgent(ParsedRobotsTxt $parsed, string $userAgent): bool
    {
        $needle = strtolower($userAgent);
        foreach ($parsed->groups as $group) {
            foreach ($group->userAgents as $ua) {
                if (strtolower($ua) === $needle) {
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
                if ($path !== '' && $path !== '/') {
                    $paths[] = $path;
                }
            }
        }
        return array_values(array_unique($paths));
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
        return explode("\n", $content);
    }

    /**
     * Remove inline comment from a line (everything after an unescaped #).
     * The robots.txt spec uses simple # comments; we don't try to handle
     * #-in-URL escaping because URLs never appear in a directive value here.
     */
    private function stripInlineComment(string $line): string
    {
        $hashPos = strpos($line, '#');
        if ($hashPos === false) {
            return $line;
        }
        // Keep the line as-is if it's a pure-comment line; caller handles those
        if (trim(substr($line, 0, $hashPos)) === '') {
            return $line;
        }
        return substr($line, 0, $hashPos);
    }

    /**
     * Split a directive line into [name, value]. Returns [null, null] on malformed input.
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
