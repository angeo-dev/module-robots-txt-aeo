<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model\Sanitizer;

use Angeo\RobotsTxtAeo\Model\Sanitizer\RobotsLineSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Angeo\RobotsTxtAeo\Model\Sanitizer\RobotsLineSanitizer
 */
class RobotsLineSanitizerTest extends TestCase
{
    private RobotsLineSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new RobotsLineSanitizer();
    }

    public function testNewlineCannotForgeADirective(): void
    {
        // The whole point: a stored value that reached config without passing
        // a backend model must not be able to add lines to a public file.
        $this->assertSame(
            '/User-agent: *Disallow: /',
            $this->sanitizer->value("/\nUser-agent: *\nDisallow: /")
        );
    }

    public function testCarriageReturnAndNullAreRemoved(): void
    {
        $this->assertSame('/catalog/', $this->sanitizer->value("/catalog/\r\0"));
    }

    public function testInlineCommentIsCut(): void
    {
        $this->assertSame('/catalog/', $this->sanitizer->value('/catalog/ # keep this out'));
    }

    public function testValueIsLengthCapped(): void
    {
        $long = '/' . str_repeat('a', RobotsLineSanitizer::MAX_VALUE_LENGTH * 2);

        $this->assertSame(RobotsLineSanitizer::MAX_VALUE_LENGTH, strlen($this->sanitizer->value($long)));
    }

    public function testUserAgentTokenLosesWhitespace(): void
    {
        $this->assertSame('GPTBot', $this->sanitizer->userAgentToken("  GPT Bot \n"));
    }

    public function testBlockCapsLineCount(): void
    {
        $result = $this->sanitizer->block(str_repeat("Disallow: /a\n", RobotsLineSanitizer::MAX_CUSTOM_LINES * 2));

        $this->assertLessThanOrEqual(
            RobotsLineSanitizer::MAX_CUSTOM_LINES,
            substr_count($result, "\n") + 1
        );
    }

    public function testBlockNormalisesLineEndings(): void
    {
        $this->assertSame("a\nb", $this->sanitizer->block("a\r\nb"));
    }

    public function testPathsAreCleanedAndDeduplicated(): void
    {
        $this->assertSame(
            ['/a', '/b'],
            $this->sanitizer->paths(['/a', " /a ", '', "\n", '/b'])
        );
    }

    public function testEmptyValuesAreReturnedAsEmptyString(): void
    {
        $this->assertSame('', $this->sanitizer->value("#comment only"));
        $this->assertSame('', $this->sanitizer->value("\n\n"));
    }
}
