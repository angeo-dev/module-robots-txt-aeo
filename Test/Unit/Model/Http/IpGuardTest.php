<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model\Http;

use Angeo\RobotsTxtAeo\Model\Http\IpGuard;
use Angeo\RobotsTxtAeo\Model\Verify\CidrMatcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Angeo\RobotsTxtAeo\Model\Http\IpGuard
 */
class IpGuardTest extends TestCase
{
    private IpGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new IpGuard(new CidrMatcher());
    }

    /**
     * @dataProvider blockedAddresses
     */
    public function testBlocksNonRoutableAddresses(string $ip, string $why): void
    {
        $this->assertFalse($this->guard->isAllowedAddress($ip), $why);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function blockedAddresses(): array
    {
        return [
            'loopback'          => ['127.0.0.1',        'loopback'],
            'loopback range'    => ['127.53.1.9',       'the whole 127/8 is loopback'],
            'private 10'        => ['10.0.0.1',         'RFC 1918'],
            'private 172'       => ['172.16.5.4',       'RFC 1918'],
            'private 192'       => ['192.168.1.1',      'RFC 1918'],
            'cloud metadata'    => ['169.254.169.254',  'the endpoint every SSRF write-up targets'],
            'link local'        => ['169.254.1.1',      'link-local'],
            'cgnat'             => ['100.64.0.1',       'carrier-grade NAT'],
            'this network'      => ['0.0.0.0',          'unspecified'],
            'multicast'         => ['224.0.0.1',        'multicast'],
            'reserved'          => ['255.255.255.255',  'broadcast'],
            'v6 loopback'       => ['::1',              'IPv6 loopback'],
            'v6 unique local'   => ['fd12:3456::1',     'IPv6 ULA'],
            'v6 link local'     => ['fe80::1',          'IPv6 link-local'],
            'v4 mapped'         => ['::ffff:127.0.0.1', 'IPv4-mapped loopback must not slip past the v6 list'],
            'v4 mapped meta'    => ['::ffff:169.254.169.254', 'mapped metadata endpoint'],
            'not an address'    => ['example.com',      'a name is not an address'],
            'empty'             => ['',                 'empty input'],
        ];
    }

    /**
     * @dataProvider allowedAddresses
     */
    public function testAllowsPubliclyRoutableAddresses(string $ip): void
    {
        $this->assertTrue($this->guard->isAllowedAddress($ip));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedAddresses(): array
    {
        return [
            'google dns' => ['8.8.8.8'],
            'cloudflare' => ['1.1.1.1'],
            'v6 public'  => ['2606:4700:4700::1111'],
        ];
    }

    public function testLiteralPublicAddressResolvesToItself(): void
    {
        $this->assertSame(['8.8.8.8'], $this->guard->resolveAllowedAddresses('8.8.8.8'));
    }

    public function testLiteralPrivateAddressResolvesToNothing(): void
    {
        $this->assertSame([], $this->guard->resolveAllowedAddresses('127.0.0.1'));
        $this->assertSame([], $this->guard->resolveAllowedAddresses('169.254.169.254'));
    }

    public function testEmptyHostResolvesToNothing(): void
    {
        $this->assertSame([], $this->guard->resolveAllowedAddresses('   '));
    }

    public function testHostIsRefusedWhenAnyAddressIsPrivate(): void
    {
        $guard = new class (new CidrMatcher()) extends IpGuard {
            protected function lookup(string $host): array
            {
                // A split answer is the shape a DNS rebinding attack takes.
                return ['93.184.216.34', '127.0.0.1'];
            }
        };

        $this->assertSame([], $guard->resolveAllowedAddresses('rebind.example'));
    }

    public function testHostIsAcceptedWhenEveryAddressIsPublic(): void
    {
        $guard = new class (new CidrMatcher()) extends IpGuard {
            protected function lookup(string $host): array
            {
                return ['93.184.216.34', '2606:4700::1111'];
            }
        };

        $this->assertSame(
            ['93.184.216.34', '2606:4700::1111'],
            $guard->resolveAllowedAddresses('shop.example')
        );
    }
}
