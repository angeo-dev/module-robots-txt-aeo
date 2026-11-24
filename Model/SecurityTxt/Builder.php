<?php
declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Model\SecurityTxt;

/**
 * /.well-known/security.txt per RFC 9116 (4.2.0).
 *
 * Contact and Expires are required. Expires is written fresh on every
 * response (today + the configured days, at most 365), so the file never
 * goes stale — RFC 9116 asks for less than a year. Canonical points at the
 * URL that served it. Contact values must be mailto:, https: or tel: URIs; a
 * bare e-mail address becomes mailto:. No valid contact → no file (404).
 *
 * @since 4.2.0
 */
class Builder
{
    public const MAX_DAYS = 365;

    /**
     * @param list<string> $contacts
     */
    public static function build(
        array $contacts,
        int $expiresDays,
        \DateTimeImmutable $now,
        string $canonicalUrl,
        string $languages = '',
        string $policyUrl = '',
        string $acknowledgmentsUrl = ''
    ): ?string {
        $contacts = self::contacts($contacts);
        if ($contacts === []) {
            return null;
        }
        $days = max(1, min(self::MAX_DAYS, $expiresDays));
        $lines = [];
        foreach ($contacts as $c) {
            $lines[] = 'Contact: ' . $c;
        }
        $lines[] = 'Expires: ' . $now->setTimezone(new \DateTimeZone('UTC'))
            ->modify('+' . $days . ' days')->setTime(0, 0)->format('Y-m-d\TH:i:s\Z');
        if (($l = self::languages($languages)) !== '') {
            $lines[] = 'Preferred-Languages: ' . $l;
        }
        foreach (['Policy' => $policyUrl, 'Acknowledgments' => $acknowledgmentsUrl, 'Canonical' => $canonicalUrl] as $field => $url) {
            if (self::isHttps($url)) {
                $lines[] = $field . ': ' . $url;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $raw
     * @return list<string>
     */
    public static function contacts(array $raw): array
    {
        $out = [];
        foreach ($raw as $value) {
            $value = trim((string) $value);
            if ($value === '' || preg_match('/[\s\x00-\x1f]/', $value) === 1) {
                continue;
            }
            if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                $value = 'mailto:' . $value;
            }
            $ok = (str_starts_with($value, 'mailto:') && filter_var(substr($value, 7), FILTER_VALIDATE_EMAIL) !== false)
                || self::isHttps($value)
                || preg_match('/^tel:\+?[0-9().-]{5,}$/', $value) === 1;
            if ($ok && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return array_slice($out, 0, 10);
    }

    private static function isHttps(string $url): bool
    {
        return str_starts_with($url, 'https://') && filter_var($url, FILTER_VALIDATE_URL) !== false
            && preg_match('/[\s\x00-\x1f]/', $url) !== 1;
    }

    /** "en, nl ,uk" → "en, nl, uk"; drops anything that is not a language tag. */
    private static function languages(string $raw): string
    {
        $tags = array_filter(array_map('trim', explode(',', $raw)),
            static fn (string $t): bool => preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $t) === 1);

        return implode(', ', array_values(array_unique($tags)));
    }
}
