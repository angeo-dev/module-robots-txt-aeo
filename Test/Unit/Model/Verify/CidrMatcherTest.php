<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model\Verify;

use Angeo\RobotsTxtAeo\Model\Verify\CidrMatcher;
use PHPUnit\Framework\TestCase;

class CidrMatcherTest extends TestCase
{
    private CidrMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new CidrMatcher();
    }

    public function testIpv4Containment(): void
    {
        $this->assertTrue($this->matcher->contains('20.42.10.176/28', '20.42.10.180'));
        $this->assertFalse($this->matcher->contains('20.42.10.176/28', '20.42.10.192'));
        $this->assertTrue($this->matcher->contains('10.0.0.0/8', '10.255.255.255'));
        $this->assertFalse($this->matcher->contains('10.0.0.0/8', '11.0.0.1'));
    }

    public function testIpv4NonOctetAlignedPrefix(): void
    {
        // /28 — boundary inside a byte.
        $this->assertTrue($this->matcher->contains('192.168.1.16/28', '192.168.1.31'));
        $this->assertFalse($this->matcher->contains('192.168.1.16/28', '192.168.1.32'));
    }

    public function testIpv6Containment(): void
    {
        $this->assertTrue($this->matcher->contains('2a09:bac0::/32', '2a09:bac0:1:2::3'));
        $this->assertFalse($this->matcher->contains('2a09:bac0::/32', '2a09:bac1::1'));
    }

    public function testBareAddressTreatedAsHostRange(): void
    {
        $this->assertTrue($this->matcher->contains('203.0.113.7', '203.0.113.7'));
        $this->assertFalse($this->matcher->contains('203.0.113.7', '203.0.113.8'));
    }

    public function testFamilyMismatchIsFalseNotError(): void
    {
        $this->assertFalse($this->matcher->contains('10.0.0.0/8', '::1'));
        $this->assertFalse($this->matcher->contains('2a09:bac0::/32', '10.0.0.1'));
    }

    public function testMalformedInputNeverThrows(): void
    {
        $this->assertFalse($this->matcher->contains('not-a-cidr/xx', '10.0.0.1'));
        $this->assertFalse($this->matcher->contains('10.0.0.0/99', '10.0.0.1'));
        $this->assertFalse($this->matcher->contains('', ''));
    }

    public function testExtractRangesFromOpenAiShapedPayload(): void
    {
        $payload = [
            'creationTime' => '2026-01-01',
            'prefixes' => [
                ['ipv4Prefix' => '20.42.10.176/28'],
                ['ipv6Prefix' => '2a09:bac0::/32'],
            ],
        ];

        $ranges = $this->matcher->extractRanges($payload);
        $this->assertContains('20.42.10.176/28', $ranges);
        $this->assertContains('2a09:bac0::/32', $ranges);
        $this->assertNotContains('2026-01-01', $ranges, 'non-IP strings must be ignored');
    }

    public function testExtractRangesFromFlatList(): void
    {
        $ranges = $this->matcher->extractRanges(['1.2.3.0/24', 'garbage', '5.6.7.8']);
        $this->assertSame(['1.2.3.0/24', '5.6.7.8'], $ranges);
    }
}
