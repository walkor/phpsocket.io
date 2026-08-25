<?php

namespace PHPSocketIO\Tests\Unit;

use PHPSocketIO\Client;
use PHPSocketIO\Parser\Parser;
use PHPSocketIO\SocketIO;
use PHPSocketIO\Tests\Unit\Fixtures\FakeEngineConn;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    public function testConstructWiresIdAndRequestFromConn(): void
    {
        $io = new SocketIO();
        $conn = FakeEngineConn::make('conn1');

        $client = new Client($io, $conn);

        $this->assertSame('conn1', $client->id);
        $this->assertSame($conn->request, $client->request);
    }

    public function testPacketEncodesAndWritesToConn(): void
    {
        $io = new SocketIO();
        $conn = FakeEngineConn::make();
        $client = new Client($io, $conn);

        $client->packet(['type' => Parser::EVENT, 'data' => ['chat message', 'hi']]);

        $this->assertCount(1, $conn->writeCalls);
        $this->assertSame('2["chat message","hi"]', $conn->writeCalls[0]);
    }

    public function testPacketDoesNothingWhenConnNotOpen(): void
    {
        $io = new SocketIO();
        $conn = FakeEngineConn::make();
        $client = new Client($io, $conn);
        $conn->readyState = 'closed';

        $client->packet(['type' => Parser::EVENT, 'data' => ['chat message', 'hi']]);

        $this->assertCount(0, $conn->writeCalls);
    }

    public function testWriteToEngineStripsSpuriousNspKeyFromPreEncodedArray(): void
    {
        $io = new SocketIO();
        $conn = FakeEngineConn::make();
        $client = new Client($io, $conn);

        // Mirrors what Socket::packet() hands to Client::packet() for a
        // pre-encoded (room broadcast) delivery: an array of already-encoded
        // packet strings with a stray 'nsp' key mixed in.
        $client->writeToEngine([0 => '2["chat message","hi"]', 'nsp' => '/']);

        $this->assertSame(['2["chat message","hi"]'], $conn->writeCalls);
    }

    public function testOndataDecodesAndDispatchesConnectPacket(): void
    {
        $io = new SocketIO();
        $conn = FakeEngineConn::make();
        $client = new Client($io, $conn);

        $client->ondata('0');

        $this->assertArrayHasKey('/', $client->nsps);
    }

    public function testOndataOnIllegalAttachmentCountRoutesToOnerror(): void
    {
        $io = new SocketIO();
        $conn = FakeEngineConn::make();
        $client = new Client($io, $conn);

        // Binary event type with an attachment count that overflows a PHP
        // int is illegal per Decoder::decodeString() and throws -- unlike a
        // non-numeric count, this comparison isn't affected by PHP 8's
        // "saner string to number comparisons" RFC, so it throws the same
        // way across our whole supported PHP range.
        $client->ondata('5' . str_repeat('9', 25) . '-["event"]');

        // onerror() -> onclose() -> destroy() nulls out the connection.
        $this->assertNull($client->conn);
    }

    public function testConnectBuffersNonDefaultNamespaceUntilDefaultConnects(): void
    {
        $io = new SocketIO();
        $io->of('/chat'); // namespaces must be declared server-side before a client can join them
        $conn = FakeEngineConn::make();
        $client = new Client($io, $conn);

        $client->connect('/chat');
        $this->assertArrayNotHasKey('/chat', $client->nsps);
        $this->assertArrayHasKey('/chat', $client->connectBuffer);

        $client->connect('/');
        $this->assertArrayHasKey('/', $client->nsps);
        $this->assertArrayHasKey('/chat', $client->nsps);
        $this->assertSame([], $client->connectBuffer);
    }

    public function testConnectSendsErrorPacketForUnknownNamespace(): void
    {
        $io = new SocketIO();
        $conn = FakeEngineConn::make();
        $client = new Client($io, $conn);

        $client->connect('/does-not-exist');

        $this->assertCount(1, $conn->writeCalls);
        $decoded = (new \PHPSocketIO\Parser\Decoder())->decodeString($conn->writeCalls[0]);
        $this->assertEquals(Parser::ERROR, $decoded['type']);
    }

    public function testOndecodedRoutesEventPacketToMatchingNamespaceSocket(): void
    {
        $io = new SocketIO();
        $conn = FakeEngineConn::make();
        $client = new Client($io, $conn);
        $client->connect('/');

        $received = null;
        $socket = $client->nsps['/'];
        $socket->on('chat message', function ($msg) use (&$received) {
            $received = $msg;
        });

        $client->ondecoded(['type' => Parser::EVENT, 'nsp' => '/', 'data' => ['chat message', 'hi']]);

        $this->assertSame('hi', $received);
    }

    public function testDisconnectClosesAllSocketsAndConnection(): void
    {
        $io = new SocketIO();
        $conn = FakeEngineConn::make();
        $client = new Client($io, $conn);
        $client->connect('/');

        $client->disconnect();

        // disconnect() sets $sockets = [] but immediately calls close(),
        // which (via onclose()) tears the client down fully and nulls it.
        $this->assertNull($client->sockets);
        $this->assertCount(1, $conn->closeCalls);
    }

    public function testRemoveUnregistersSocketAndNamespaceEntry(): void
    {
        $io = new SocketIO();
        $conn = FakeEngineConn::make();
        $client = new Client($io, $conn);
        $client->connect('/');
        $socket = $client->nsps['/'];

        $client->remove($socket);

        $this->assertArrayNotHasKey($socket->id, $client->sockets);
        $this->assertArrayNotHasKey('/', $client->nsps);
    }

    public function testOncloseIsIdempotentAfterDestroy(): void
    {
        $io = new SocketIO();
        $conn = FakeEngineConn::make();
        $client = new Client($io, $conn);

        $client->onclose('first');
        // Second call must not error even though destroy() already ran.
        $client->onclose('second');

        $this->assertNull($client->conn);
    }

    public function testOnerrorPropagatesToSocketsAndClosesConnection(): void
    {
        $io = new SocketIO();
        $conn = FakeEngineConn::make();
        $client = new Client($io, $conn);
        $client->connect('/');
        $socket = $client->nsps['/'];

        $seenError = null;
        $socket->on('error', function ($err) use (&$seenError) {
            $seenError = $err;
        });

        $client->onerror('boom');

        $this->assertSame('boom', $seenError);
        $this->assertNull($client->conn);
    }
}
