<?php

namespace PHPSocketIO\Tests\Unit;

use PHPSocketIO\Parser\Decoder;
use PHPSocketIO\Parser\Encoder;
use PHPSocketIO\Parser\Parser;
use PHPUnit\Framework\TestCase;

class ParserTest extends TestCase
{
    private Encoder $encoder;
    private Decoder $decoder;

    protected function setUp(): void
    {
        $this->encoder = new Encoder();
        $this->decoder = new Decoder();
    }

    public function testEncodeSimpleEvent(): void
    {
        $packet = ['type' => Parser::EVENT, 'data' => ['chat message', 'hi']];

        $this->assertSame(
            ['2["chat message","hi"]'],
            $this->encoder->encode($packet)
        );
    }

    public function testEncodeEventWithCustomNamespace(): void
    {
        $packet = ['type' => Parser::EVENT, 'nsp' => '/admin', 'data' => ['test']];

        $this->assertSame(
            ['2/admin,["test"]'],
            $this->encoder->encode($packet)
        );
    }

    public function testEncodeAckWithId(): void
    {
        $packet = ['type' => Parser::ACK, 'id' => 3, 'data' => [1]];

        $this->assertSame(
            ['33[1]'],
            $this->encoder->encode($packet)
        );
    }

    public function testEncodeBinaryEventIsUnsupported(): void
    {
        $packet = ['type' => Parser::BINARY_EVENT, 'data' => ['test']];

        $this->assertSame([], $this->encoder->encode($packet));
    }

    public function testDecodeSimpleEvent(): void
    {
        $packet = $this->decoder->decodeString('2["chat message","hi"]');

        $this->assertEquals(Parser::EVENT, $packet['type']);
        $this->assertSame('/', $packet['nsp']);
        $this->assertSame(['chat message', 'hi'], $packet['data']);
    }

    public function testDecodeEventWithCustomNamespace(): void
    {
        $packet = $this->decoder->decodeString('2/admin,["test"]');

        $this->assertEquals(Parser::EVENT, $packet['type']);
        $this->assertSame('/admin', $packet['nsp']);
        $this->assertSame(['test'], $packet['data']);
    }

    public function testDecodeAckWithId(): void
    {
        $packet = $this->decoder->decodeString('33[1]');

        $this->assertEquals(Parser::ACK, $packet['type']);
        $this->assertSame(3, $packet['id']);
        $this->assertSame([1], $packet['data']);
    }

    public function testDecodeUnknownTypeReturnsError(): void
    {
        $packet = $this->decoder->decodeString('9["test"]');

        $this->assertEquals(Parser::ERROR, $packet['type']);
        $this->assertSame('parser error', $packet['data']);
    }

    public function testAddEmitsDecodedEvent(): void
    {
        $decoded = null;
        $this->decoder->on('decoded', function ($packet) use (&$decoded) {
            $decoded = $packet;
        });

        $this->decoder->add('2["chat message","hi"]');

        $this->assertNotNull($decoded);
        $this->assertSame(['chat message', 'hi'], $decoded['data']);
    }

    /**
     * @dataProvider roundTripProvider
     */
    public function testEncodeDecodeRoundTrip(array $packet): void
    {
        [$encoded] = $this->encoder->encode($packet);
        $decoded = $this->decoder->decodeString($encoded);

        $this->assertEquals($packet['type'], $decoded['type']);
        $this->assertSame($packet['data'], $decoded['data']);
        $this->assertSame($packet['nsp'] ?? '/', $decoded['nsp']);
    }

    public static function roundTripProvider(): array
    {
        return [
            'default namespace' => [['type' => Parser::EVENT, 'data' => ['ping']]],
            'custom namespace' => [['type' => Parser::EVENT, 'nsp' => '/chat', 'data' => ['ping']]],
            'nested data' => [['type' => Parser::EVENT, 'data' => ['event', ['a' => 1, 'b' => [1, 2, 3]]]]],
        ];
    }
}
