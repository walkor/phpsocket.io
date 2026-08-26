<?php

namespace PHPSocketIO\Tests\Unit\Engine\Protocols\WebSocket;

use PHPSocketIO\Engine\Protocols\WebSocket\RFC6455;
use PHPSocketIO\Tests\Unit\Fixtures\RecordingHttpResponse;
use PHPUnit\Framework\TestCase;
use stdClass;
use Workerman\Connection\ConnectionInterface;

class FakeRfc6455Connection extends ConnectionInterface
{
    public array $sendCalls = [];
    public array $closeCalls = [];
    public array $consumeRecvBufferCalls = [];

    public function send($send_buffer, $raw = false)
    {
        $this->sendCalls[] = [$send_buffer, $raw];
    }

    public function close($data = null)
    {
        $this->closeCalls[] = $data;
    }

    public function consumeRecvBuffer($length): void
    {
        $this->consumeRecvBufferCalls[] = $length;
    }

    public function getRemoteIp()
    {
        return '127.0.0.1';
    }

    public function getRemotePort()
    {
        return 12345;
    }

    public function getRemoteAddress()
    {
        return '127.0.0.1:12345';
    }

    public function getLocalIp()
    {
        return '127.0.0.1';
    }

    public function getLocalPort()
    {
        return 2026;
    }

    public function getLocalAddress()
    {
        return '127.0.0.1:2026';
    }

    public function isIPv4()
    {
        return true;
    }

    public function isIPv6()
    {
        return false;
    }
}

class RFC6455Test extends TestCase
{
    /**
     * Builds a client->server RFC6455 frame: masked, as real browsers always send.
     */
    private function buildFrame(int $opcode, string $payload = '', bool $fin = true, array $mask = [0x01, 0x02, 0x03, 0x04]): string
    {
        $byte0 = chr(($fin ? 0x80 : 0x00) | $opcode);
        $len = strlen($payload);
        if ($len <= 125) {
            $head = $byte0 . chr(0x80 | $len);
        } elseif ($len <= 65535) {
            $head = $byte0 . chr(0x80 | 126) . pack('n', $len);
        } else {
            $head = $byte0 . chr(0x80 | 127) . pack('N2', 0, $len);
        }
        $maskBytes = implode('', array_map('chr', $mask));
        $masked = '';
        for ($i = 0; $i < $len; $i++) {
            $masked .= chr(ord($payload[$i]) ^ $mask[$i % 4]);
        }
        return $head . $maskBytes . $masked;
    }

    public function testInputReturnsZeroWhenHeaderIncomplete(): void
    {
        $connection = new FakeRfc6455Connection();

        $this->assertSame(0, RFC6455::input('abc', $connection));
    }

    public function testInputReturnsFullFrameLengthForSmallPayload(): void
    {
        $connection = new FakeRfc6455Connection();
        $connection->websocketCurrentFrameLength = 0;
        $connection->websocketDataBuffer = '';
        $frame = $this->buildFrame(0x1, 'hi');

        $this->assertSame(strlen($frame), RFC6455::input($frame, $connection));
    }

    public function testInputReturnsExpectedTotalLengthEvenWhenPayloadNotFullyArrivedYet(): void
    {
        // For a fresh frame, input() reports the total length it expects up
        // front (per Workerman's ProtocolInterface contract) -- it's the
        // caller (TcpConnection) that compares this against how much data
        // has actually arrived and waits for more if needed.
        $connection = new FakeRfc6455Connection();
        $connection->websocketCurrentFrameLength = 0;
        $frame = $this->buildFrame(0x1, 'hello world');
        $partial = substr($frame, 0, strlen($frame) - 3);

        $this->assertSame(strlen($frame), RFC6455::input($partial, $connection));
    }

    public function testInputReturnsZeroWhenMidContinuationFrameStillIncomplete(): void
    {
        // Once websocketCurrentFrameLength is already set (a non-final frame
        // was seen previously), input() DOES report 0 for "not enough data
        // yet" rather than the total length, since the caller already knows
        // the total from the first input() call.
        $connection = new FakeRfc6455Connection();
        $connection->websocketCurrentFrameLength = 100;

        $this->assertSame(0, RFC6455::input(str_repeat('x', 20), $connection));
    }

    public function testInputHandles16BitExtendedLength(): void
    {
        $connection = new FakeRfc6455Connection();
        $connection->websocketCurrentFrameLength = 0;
        $connection->websocketDataBuffer = '';
        $payload = str_repeat('x', 200); // > 125, forces the 126 marker + 2-byte length
        $frame = $this->buildFrame(0x1, $payload);

        $this->assertSame(strlen($frame), RFC6455::input($frame, $connection));
    }

    public function testInputReturnsZeroWhenExtendedLengthHeaderIncomplete(): void
    {
        $connection = new FakeRfc6455Connection();
        $connection->websocketCurrentFrameLength = 0;
        $payload = str_repeat('x', 200);
        $frame = $this->buildFrame(0x1, $payload);
        // Truncate to just past the 126 marker, before the 2-byte extended length is fully in.
        $truncated = substr($frame, 0, 7);

        $this->assertSame(0, RFC6455::input($truncated, $connection));
    }

    public function testInputHandles64BitExtendedLength(): void
    {
        $connection = new FakeRfc6455Connection();
        $connection->websocketCurrentFrameLength = 0;
        $connection->websocketDataBuffer = '';
        $payload = str_repeat('x', 70000); // > 65535, forces the 127 marker + 8-byte length
        $frame = $this->buildFrame(0x1, $payload);

        $this->assertSame(strlen($frame), RFC6455::input($frame, $connection));
    }

    public function testInputOnPingSendsDefaultPongWhenNoCallbackRegistered(): void
    {
        $connection = new FakeRfc6455Connection();
        $connection->websocketCurrentFrameLength = 0;
        $frame = $this->buildFrame(0x9, ''); // ping, no payload

        RFC6455::input($frame, $connection);

        $this->assertSame([[pack('H*', '8a00'), true]], $connection->sendCalls);
        $this->assertSame([RFC6455::MIN_HEAD_LEN], $connection->consumeRecvBufferCalls);
    }

    public function testInputOnPingInvokesRegisteredCallbackInsteadOfDefaultPong(): void
    {
        $connection = new FakeRfc6455Connection();
        $connection->websocketCurrentFrameLength = 0;
        $called = false;
        $connection->onWebSocketPing = function () use (&$called) {
            $called = true;
        };
        $frame = $this->buildFrame(0x9, '');

        RFC6455::input($frame, $connection);

        $this->assertTrue($called);
        $this->assertSame([], $connection->sendCalls);
    }

    public function testInputOnCloseClosesConnectionWhenNoCallbackRegistered(): void
    {
        $connection = new FakeRfc6455Connection();
        $connection->websocketCurrentFrameLength = 0;
        $frame = $this->buildFrame(0x8, '');

        $result = RFC6455::input($frame, $connection);

        $this->assertSame(0, $result);
        $this->assertCount(1, $connection->closeCalls);
    }

    public function testInputOnUnknownOpcodeClosesConnection(): void
    {
        $connection = new FakeRfc6455Connection();
        $connection->websocketCurrentFrameLength = 0;
        $frame = $this->buildFrame(0x3, ''); // reserved/unused opcode

        $result = RFC6455::input($frame, $connection);

        $this->assertSame(0, $result);
        $this->assertCount(1, $connection->closeCalls);
    }

    public function testDecodeUnmasksSmallPayload(): void
    {
        $connection = new FakeRfc6455Connection();
        $connection->websocketCurrentFrameLength = 0;
        $connection->websocketDataBuffer = '';
        $frame = $this->buildFrame(0x1, 'hello');

        $this->assertSame('hello', RFC6455::decode($frame, $connection));
    }

    public function testDecodeUnmasks16BitExtendedLengthPayload(): void
    {
        $connection = new FakeRfc6455Connection();
        $connection->websocketCurrentFrameLength = 0;
        $connection->websocketDataBuffer = '';
        $payload = str_repeat('ab', 100); // 200 bytes, forces 126 marker
        $frame = $this->buildFrame(0x1, $payload);

        $this->assertSame($payload, RFC6455::decode($frame, $connection));
    }

    public function testDecodeAccumulatesAcrossFragmentedFrames(): void
    {
        $connection = new FakeRfc6455Connection();
        $connection->websocketDataBuffer = '';

        // First (non-final) fragment: input() buffers it internally.
        $connection->websocketCurrentFrameLength = 0;
        $first = $this->buildFrame(0x1, 'Hello, ', false);
        RFC6455::input($first, $connection);
        $this->assertSame('Hello, ', $connection->websocketDataBuffer);

        // Final fragment: decode() is what Workerman calls once input() signals
        // the boundary, with websocketCurrentFrameLength reset to 0 by then.
        $connection->websocketCurrentFrameLength = 0;
        $second = $this->buildFrame(0x0, 'world!', true);
        $result = RFC6455::decode($second, $connection);

        $this->assertSame('Hello, world!', $result);
        $this->assertSame('', $connection->websocketDataBuffer);
    }

    public function testEncodeSmallPayloadUsesSingleByteLength(): void
    {
        $connection = new FakeRfc6455Connection();
        $connection->websocketHandshake = true;
        $connection->websocketType = RFC6455::BINARY_TYPE_BLOB;

        $encoded = RFC6455::encode('hi', $connection);

        $this->assertSame(RFC6455::BINARY_TYPE_BLOB . chr(2) . 'hi', $encoded);
    }

    public function testEncode16BitLengthForMediumPayload(): void
    {
        $connection = new FakeRfc6455Connection();
        $connection->websocketHandshake = true;
        $connection->websocketType = RFC6455::BINARY_TYPE_BLOB;
        $payload = str_repeat('x', 200);

        $encoded = RFC6455::encode($payload, $connection);

        $this->assertSame(RFC6455::BINARY_TYPE_BLOB . chr(126) . pack('n', 200) . $payload, $encoded);
    }

    public function testEncode64BitLengthForLargePayload(): void
    {
        $connection = new FakeRfc6455Connection();
        $connection->websocketHandshake = true;
        $connection->websocketType = RFC6455::BINARY_TYPE_BLOB;
        $payload = str_repeat('x', 70000);

        $encoded = RFC6455::encode($payload, $connection);

        $this->assertSame(RFC6455::BINARY_TYPE_BLOB . chr(127) . pack('xxxxN', 70000) . $payload, $encoded);
    }

    public function testEncodeBuffersDataUntilHandshakeCompletesInsteadOfSending(): void
    {
        $connection = new FakeRfc6455Connection();
        $connection->websocketHandshake = false;

        $encoded = RFC6455::encode('too early', $connection);

        $this->assertSame('', $encoded);
        $this->assertStringContainsString('too early', $connection->websocketTmpData);
    }

    public function testDealHandshakeComputesRfc6455AcceptHeader(): void
    {
        $connection = new FakeRfc6455Connection();
        $req = new stdClass();
        // The canonical example key/accept pair straight from RFC 6455 section 1.3.
        $req->headers = ['sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ=='];
        $res = new RecordingHttpResponse();

        RFC6455::dealHandshake($connection, $req, $res);

        [, , $headers] = $res->writeHeadCalls[0];
        $this->assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', $headers['Sec-WebSocket-Accept']);
        $this->assertSame(101, $res->writeHeadCalls[0][0]);
        $this->assertTrue($connection->websocketHandshake);
    }

    public function testDealHandshakeReturnsZeroAndSends400WhenKeyMissing(): void
    {
        $connection = new FakeRfc6455Connection();
        $req = new stdClass();
        $req->headers = [];
        $res = new RecordingHttpResponse();

        $result = RFC6455::dealHandshake($connection, $req, $res);

        $this->assertSame(0, $result);
        $this->assertSame(400, $res->writeHeadCalls[0][0]);
    }

    public function testDealHandshakeAbortsWhenOnWebSocketConnectMarksResponseUnwritable(): void
    {
        $connection = new FakeRfc6455Connection();
        $req = new stdClass();
        $req->headers = ['sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ=='];
        $res = new RecordingHttpResponse();
        $res->writable = false;
        $connection->onWebSocketConnect = function () {
        };

        $result = RFC6455::dealHandshake($connection, $req, $res);

        $this->assertFalse($result);
        $this->assertSame([], $res->writeHeadCalls);
    }

    public function testDealHandshakeFlushesBufferedDataAfterCompletingHandshake(): void
    {
        $connection = new FakeRfc6455Connection();
        $connection->websocketTmpData = 'buffered-frame-bytes';
        $req = new stdClass();
        $req->headers = ['sec-websocket-key' => 'dGhlIHNhbXBsZSBub25jZQ=='];
        $res = new RecordingHttpResponse();

        RFC6455::dealHandshake($connection, $req, $res);

        $this->assertSame([['buffered-frame-bytes', true]], $connection->sendCalls);
        $this->assertSame('', $connection->websocketTmpData);
    }
}
