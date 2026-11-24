<?php
declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model\SecurityTxt;

use Angeo\RobotsTxtAeo\Model\SecurityTxt\Builder;
use PHPUnit\Framework\TestCase;

/**
 * 4.2.0: RFC 9116 security.txt.
 */
class BuilderTest extends TestCase
{
    public function testRequiredFieldsAndCanonical(): void
    {
        $body = Builder::build(['security@shop.example', 'https://shop.example/security'], 180,
            new \DateTimeImmutable('2026-11-23T19:00:00+01:00'), 'https://shop.example/.well-known/security.txt', 'en, nl ,x y', 'https://shop.example/disclosure');

        self::assertSame(
            "Contact: mailto:security@shop.example\n"
            . "Contact: https://shop.example/security\n"
            . "Expires: 2027-05-22T00:00:00Z\n"
            . "Preferred-Languages: en, nl\n"
            . "Policy: https://shop.example/disclosure\n"
            . "Canonical: https://shop.example/.well-known/security.txt\n",
            $body
        );
    }

    public function testNoValidContactMeansNoFile(): void
    {
        self::assertNull(Builder::build(['not an address', 'http://insecure.example', "x@y.example\nInjected: 1"], 30,
            new \DateTimeImmutable(), 'https://s.example/.well-known/security.txt'));
    }

    public function testExpiresIsCappedAtOneYear(): void
    {
        $body = (string) Builder::build(['tel:+31101234567'], 9999, new \DateTimeImmutable('2026-01-01T00:00:00Z'), '');
        self::assertStringContainsString('Expires: 2027-01-01T00:00:00Z', $body);
        self::assertStringNotContainsString('Canonical', $body);
    }
}
