<?php

declare(strict_types=1);

namespace Angeo\RobotsTxtAeo\Test\Unit\Model\Verify;

use Angeo\RobotsTxtAeo\Model\Verify\SignatureBaseBuilder;
use Angeo\RobotsTxtAeo\Model\Verify\SignatureInputParser;
use Angeo\RobotsTxtAeo\Model\Verify\SignedRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Angeo\RobotsTxtAeo\Model\Verify\SignatureInputParser
 * @covers \Angeo\RobotsTxtAeo\Model\Verify\SignatureBaseBuilder
 */
class SignatureInputParserTest extends TestCase
{
    private SignatureInputParser $parser;
    private SignatureBaseBuilder $builder;

    protected function setUp(): void
    {
        $this->parser  = new SignatureInputParser();
        $this->builder = new SignatureBaseBuilder();
    }

    public function testParsesAWebBotAuthHeaderPair(): void
    {
        $input = 'sig1=("@authority" "@method" "@path" "signature-agent");created=1755779377'
            . ';keyid="otMqcjr17mGyruktGvJU8oojQTSMHlVm7uO-lrcqbdg";expires=1755782977'
            . ';nonce="abc";tag="web-bot-auth";alg="ed25519"';

        $signatures = $this->parser->parse($input, 'sig1=:' . base64_encode(str_repeat("\x01", 64)) . ':');

        $this->assertCount(1, $signatures);
        $signature = $signatures[0];

        $this->assertSame('sig1', $signature->label);
        $this->assertSame(['@authority', '@method', '@path', 'signature-agent'], $signature->components);
        $this->assertSame('otMqcjr17mGyruktGvJU8oojQTSMHlVm7uO-lrcqbdg', $signature->keyId());
        $this->assertSame('ed25519', $signature->algorithm());
        $this->assertSame('web-bot-auth', $signature->tag());
        $this->assertSame(1755779377, $signature->created());
        $this->assertSame(1755782977, $signature->expires());
        $this->assertTrue($signature->covers('@authority'));
        $this->assertFalse($signature->covers('@query'));
    }

    public function testDropsEntriesWithoutAMatchingSignature(): void
    {
        $this->assertSame(
            [],
            $this->parser->parse('sig1=("@method");created=1', 'other=:' . base64_encode('x') . ':')
        );
    }

    public function testRefusesComponentParameters(): void
    {
        // ";req" / ";sf" change what is signed; silently ignoring them would
        // mean verifying a different message than the signer signed.
        $this->assertSame(
            [],
            $this->parser->parse(
                'sig1=("@authority" "content-digest";sf);created=1;keyid="k"',
                'sig1=:' . base64_encode(str_repeat("\x01", 64)) . ':'
            )
        );
    }

    public function testIgnoresOversizedHeaders(): void
    {
        $huge = 'sig1=("@method");x="' . str_repeat('a', SignatureInputParser::MAX_HEADER_LENGTH) . '"';

        $this->assertSame([], $this->parser->parse($huge, 'sig1=:' . base64_encode('x') . ':'));
    }

    public function testBuildsTheSignatureBaseInComponentOrder(): void
    {
        $definition = '("@method" "@path" "@authority" "signature-agent");created=1;keyid="k";alg="ed25519"';
        $signatures = $this->parser->parse(
            'sig1=' . $definition,
            'sig1=:' . base64_encode(str_repeat("\x01", 64)) . ':'
        );

        $request = new SignedRequest('get', 'https', 'Shop.Example', '/a/b?x=1', [
            'Signature-Agent' => '"https://chatgpt.com"',
        ]);

        $expected = '"@method": GET' . "\n"
            . '"@path": /a/b' . "\n"
            . '"@authority": shop.example' . "\n"
            . '"signature-agent": "https://chatgpt.com"' . "\n"
            . '"@signature-params": ' . $definition;

        $this->assertSame($expected, $this->builder->build($request, $signatures[0]));
    }

    public function testUnknownDerivedComponentCannotBeRebuilt(): void
    {
        $signatures = $this->parser->parse(
            'sig1=("@status");created=1;keyid="k"',
            'sig1=:' . base64_encode(str_repeat("\x01", 64)) . ':'
        );

        $request = new SignedRequest('GET', 'https', 'shop.example', '/');

        $this->assertNull($this->builder->build($request, $signatures[0]));
    }

    public function testMissingHeaderComponentCannotBeRebuilt(): void
    {
        $signatures = $this->parser->parse(
            'sig1=("content-digest");created=1;keyid="k"',
            'sig1=:' . base64_encode(str_repeat("\x01", 64)) . ':'
        );

        $request = new SignedRequest('GET', 'https', 'shop.example', '/');

        $this->assertNull($this->builder->build($request, $signatures[0]));
    }
}
