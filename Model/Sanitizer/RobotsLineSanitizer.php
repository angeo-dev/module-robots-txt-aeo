<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\Sanitizer;

/**
 * Output-time sanitisation for everything the module writes into robots.txt.
 *
 * Backend models validate config values on save, but a value can reach
 * ScopeConfig without ever passing through them — a direct DB write, a
 * deployment that ships app/etc/config.php, or an env.php override. robots.txt
 * is a public file that decides whether a shop is crawled at all, so the
 * renderer must not trust the config layer.
 *
 * The rules are the ones robots.txt syntax cares about:
 *   - CR / LF / NUL end the line, so a value containing them can forge extra
 *     directives ("/\nDisallow: /" would block the whole site);
 *   - "#" starts a comment, so a value containing it silently truncates the
 *     rest of the rendered line;
 *   - unbounded length and unbounded line counts are a denial-of-service on
 *     the response, not a feature.
 *
 * @since 4.0.0
 */
class RobotsLineSanitizer
{
    public const MAX_VALUE_LENGTH = 2048;
    public const MAX_CUSTOM_BYTES = 65536;
    public const MAX_CUSTOM_LINES = 2000;

    /**
     * Sanitise a directive value (a path, a URL, a preference statement).
     * Returns '' when nothing usable is left — callers must skip empty values.
     */
    public function value(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0", "\t"], '', $value);

        $hashPos = strpos($value, '#');
        if ($hashPos !== false) {
            $value = substr($value, 0, $hashPos);
        }

        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (strlen($value) > self::MAX_VALUE_LENGTH) {
            $value = substr($value, 0, self::MAX_VALUE_LENGTH);
        }

        // Drop control characters that would corrupt the emitted file.
        return (string) preg_replace('/[\x00-\x1F\x7F]/', '', $value);
    }

    /**
     * Sanitise a User-agent product token. Same rules as a value, plus no
     * whitespace — a token with a space is two tokens to a parser.
     */
    public function userAgentToken(string $token): string
    {
        $token = $this->value($token);
        if ($token === '') {
            return '';
        }
        $token = (string) preg_replace('/\s+/', '', $token);

        return $token;
    }

    /**
     * Sanitise a full directive line that we did not build ourselves
     * (preserved third-party lines, extra group directives).
     */
    public function line(string $line): string
    {
        $line = str_replace(["\r", "\n", "\0"], '', $line);
        $line = rtrim($line);

        if (strlen($line) > self::MAX_VALUE_LENGTH) {
            $line = substr($line, 0, self::MAX_VALUE_LENGTH);
        }

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $line);
    }

    /**
     * Sanitise an operator-supplied free-text block (REPLACE-mode custom
     * content). Line endings are normalised, control characters removed, and
     * both the byte size and the line count are capped.
     */
    public function block(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = str_replace("\0", '', $content);

        if (strlen($content) > self::MAX_CUSTOM_BYTES) {
            $content = substr($content, 0, self::MAX_CUSTOM_BYTES);
        }

        $lines  = explode("\n", $content);
        $lines  = array_slice($lines, 0, self::MAX_CUSTOM_LINES);
        $output = [];

        foreach ($lines as $line) {
            $output[] = $this->line($line);
        }

        return rtrim(implode("\n", $output), "\n");
    }

    /**
     * @param string[] $paths
     * @return string[] sanitised, non-empty, de-duplicated
     */
    public function paths(array $paths): array
    {
        $clean = [];
        foreach ($paths as $path) {
            $value = $this->value((string) $path);
            if ($value === '') {
                continue;
            }
            $clean[] = $value;
        }

        return array_values(array_unique($clean));
    }
}
