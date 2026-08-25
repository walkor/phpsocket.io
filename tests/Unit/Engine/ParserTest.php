<?php

namespace PHPSocketIO\Tests\Unit\Engine;

use PHPSocketIO\Engine\Parser;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    public function testEncodePacketWithData(): void
    {
        $this->assertSame('4hi', Parser::encodePacket(['type' => 'message', 'data' => 'hi']));
    }

    public function testEncodePacketWithoutData(): void
    {
        $this->assertSame('2', Parser::encodePacket(['type' => 'ping']));
    }

    public function testDecodePacketWithData(): void
    {
        $this->assertSame(
            ['type' => 'message', 'data' => 'hi'],
            Parser::decodePacket('4hi')
        );
    }

    public function testDecodePacketWithoutData(): void
    {
        $this->assertSame(['type' => 'ping'], Parser::decodePacket('2'));
    }

    public function testDecodePacketWithUnknownTypeReturnsError(): void
    {
        $this->assertSame(Parser::$err, Parser::decodePacket('9'));
    }

    public function testDecodeBase64Packet(): void
    {
        $encoded = 'b4' . base64_encode('hi');

        $this->assertSame(
            ['type' => 'message', 'data' => 'hi'],
            Parser::decodePacket($encoded)
        );
    }

    public function testEncodePayloadOfEmptyPacketsReturnsZeroMarker(): void
    {
        $this->assertSame('0:', Parser::encodePayload([]));
    }

    public function testEncodePayloadSinglePacket(): void
    {
        $this->assertSame(
            '3:4hi',
            Parser::encodePayload([['type' => 'message', 'data' => 'hi']])
        );
    }

    public function testDecodePayloadReturnsFirstPacket(): void
    {
        // The PHP payload decoder returns the first packet in the payload,
        // matching how Polling::onData is used (one packet per HTTP request).
        $payload = Parser::encodePayload([
            ['type' => 'message', 'data' => 'hi'],
            ['type' => 'ping'],
        ]);

        $this->assertSame(
            ['type' => 'message', 'data' => 'hi'],
            Parser::decodePayload($payload)
        );
    }

    public function testDecodePayloadWithEmptyStringReturnsEmptyArray(): void
    {
        // The leading regex check routes an empty string into the binary
        // decoder (no length-prefix to match), which yields an empty list
        // rather than self::$err.
        $this->assertSame([], Parser::decodePayload(''));
    }

    public function testEncodeDecodePayloadAsBinaryRoundTrip(): void
    {
        $packets = [
            ['type' => 'message', 'data' => 'hi'],
            ['type' => 'ping'],
        ];

        $encoded = Parser::encodePayload($packets, true);
        $decoded = Parser::decodePayloadAsBinary($encoded);

        $this->assertSame($packets, $decoded);
    }

    public function testDecodePayloadDelegatesToBinaryWhenNotLengthPrefixed(): void
    {
        $packets = [['type' => 'message', 'data' => 'hi']];
        $encoded = Parser::encodePayload($packets, true);

        $this->assertSame(
            Parser::decodePayloadAsBinary($encoded),
            Parser::decodePayload($encoded)
        );
    }
}
