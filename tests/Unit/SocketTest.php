<?php

namespace PHPSocketIO\Tests\Unit;

use PHPSocketIO\DefaultAdapter;
use PHPSocketIO\Parser\Parser;
use PHPSocketIO\Socket;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;

class FakeSocketClient
{
    public $id;
    public $request;
    public $conn;
    public array $packetCalls = [];
    public array $disconnectCalls = [];

    public function packet($packet, $preEncoded = false, $volatile = false): void
    {
        $this->packetCalls[] = [$packet, $preEncoded, $volatile];
    }

    public function disconnect(): void
    {
        $this->disconnectCalls[] = true;
    }

    public function remove($socket): void
    {
    }
}

class FakeSocketNsp
{
    public $server;
    public $adapter;
    public string $name = '/';
    public int $ids = 0;
    public array $connected = [];

    public function remove($socket): void
    {
    }
}

class SocketTest extends TestCase
{
    private function makeClient(string $id = 'client1'): FakeSocketClient
    {
        $client = new FakeSocketClient();
        $client->id = $id;
        $request = new stdClass();
        $request->url = '/socket.io/';
        $request->headers = [];
        $request->connection = new stdClass();
        $request->connection->encrypted = false;
        $client->request = $request;
        $conn = new stdClass();
        $conn->remoteAddress = '127.0.0.1:1234';
        $client->conn = $conn;
        return $client;
    }

    private function makeNsp(string $name = '/'): FakeSocketNsp
    {
        $nsp = new FakeSocketNsp();
        $nsp->name = $name;
        $nsp->adapter = new DefaultAdapter($nsp);
        return $nsp;
    }

    public function testIdIsClientIdOnDefaultNamespace(): void
    {
        $nsp = $this->makeNsp('/');
        $client = $this->makeClient('client1');

        $socket = new Socket($nsp, $client);

        $this->assertSame('client1', $socket->id);
    }

    public function testIdIsPrefixedWithNamespaceNameOnCustomNamespace(): void
    {
        $nsp = $this->makeNsp('/admin');
        $client = $this->makeClient('client1');

        $socket = new Socket($nsp, $client);

        $this->assertSame('/admin#client1', $socket->id);
    }

    public function testEmitSendsEventPacketThroughClient(): void
    {
        $nsp = $this->makeNsp();
        $client = $this->makeClient();
        $socket = new Socket($nsp, $client);

        $socket->emit('chat message', 'hi');

        $this->assertCount(1, $client->packetCalls);
        [$packet] = $client->packetCalls[0];
        $this->assertSame(Parser::EVENT, $packet['type']);
        $this->assertSame(['chat message', 'hi'], $packet['data']);
    }

    public function testEmitWithAckCallbackStoresAckAndIncrementsId(): void
    {
        $nsp = $this->makeNsp();
        $client = $this->makeClient();
        $socket = new Socket($nsp, $client);

        $socket->emit('message with ack', 'payload', function () {
        });

        $this->assertSame(1, $nsp->ids);
        $this->assertArrayHasKey(0, $socket->acks);
        [$packet] = $client->packetCalls[0];
        $this->assertSame(0, $packet['id']);
        $this->assertSame(['message with ack', 'payload'], $packet['data']);
    }

    public function testToRoutesEmitThroughAdapterBroadcastInsteadOfDirectPacket(): void
    {
        $nsp = $this->makeNsp();
        $sender = $this->makeClient('sender');
        $socket = new Socket($nsp, $sender);
        $nsp->connected[$socket->id] = $socket;

        $memberClient = $this->makeClient('member');
        $memberSocket = new Socket($nsp, $memberClient);
        $nsp->connected[$memberSocket->id] = $memberSocket;
        $nsp->adapter->add($memberSocket->id, 'room1');

        $socket->to('room1')->emit('chat message', 'hi');

        $this->assertCount(0, $sender->packetCalls);
        $this->assertCount(1, $memberClient->packetCalls);
    }

    public function testBroadcastExcludesSenderEvenWhenSenderIsInRoom(): void
    {
        $nsp = $this->makeNsp();
        $senderClient = $this->makeClient('sender');
        $socket = new Socket($nsp, $senderClient);
        $nsp->connected[$socket->id] = $socket;
        $nsp->adapter->add($socket->id, 'room1');

        $socket->to('room1')->emit('chat message', 'hi');

        $this->assertCount(0, $senderClient->packetCalls);
    }

    public function testJoinAddsRoomAndLeaveRemovesIt(): void
    {
        $nsp = $this->makeNsp();
        $socket = new Socket($nsp, $this->makeClient());

        $socket->join('room1');
        $this->assertArrayHasKey('room1', $socket->rooms);
        $this->assertTrue($nsp->adapter->rooms['room1'][$socket->id]);

        $socket->leave('room1');
        $this->assertArrayNotHasKey('room1', $socket->rooms);
    }

    public function testLeaveAllClearsAllRooms(): void
    {
        $nsp = $this->makeNsp();
        $socket = new Socket($nsp, $this->makeClient());
        $socket->join('room1');
        $socket->join('room2');

        $socket->leaveAll();

        $this->assertSame([], $socket->rooms);
    }

    public function testOnpacketDispatchesEventToRegisteredListener(): void
    {
        $nsp = $this->makeNsp();
        $socket = new Socket($nsp, $this->makeClient());
        $received = null;
        $socket->on('chat message', function ($msg) use (&$received) {
            $received = $msg;
        });

        $socket->onpacket(['type' => Parser::EVENT, 'data' => ['chat message', 'hi']]);

        $this->assertSame('hi', $received);
    }

    public function testOnpacketDispatchesAckToStoredCallback(): void
    {
        $nsp = $this->makeNsp();
        $socket = new Socket($nsp, $this->makeClient());
        $received = null;
        $socket->acks[7] = function ($data) use (&$received) {
            $received = $data;
        };

        $socket->onpacket(['type' => Parser::ACK, 'id' => 7, 'data' => 'result']);

        $this->assertSame('result', $received);
        $this->assertArrayNotHasKey(7, $socket->acks);
    }

    public function testAckCallbackFiresOnlyOnce(): void
    {
        $nsp = $this->makeNsp();
        $client = $this->makeClient();
        $socket = new Socket($nsp, $client);

        $ack = $socket->ack(1);
        $ack('first');
        $ack('second');

        $this->assertCount(1, $client->packetCalls);
    }

    public function testOncloseEmitsDisconnectAndTearsDownState(): void
    {
        $nsp = $this->makeNsp();
        $socket = new Socket($nsp, $this->makeClient());
        $reason = null;
        $socket->on('disconnect', function ($r) use (&$reason) {
            $reason = $r;
        });

        $socket->onclose('transport close');

        $this->assertSame('transport close', $reason);
        $this->assertFalse($socket->connected);
        $this->assertTrue($socket->disconnected);
        $this->assertNull($socket->nsp);
    }

    public function testHasBinDetectsNonPrintableBytes(): void
    {
        $nsp = $this->makeNsp();
        $socket = new Socket($nsp, $this->makeClient());
        $hasBin = new ReflectionMethod(Socket::class, 'hasBin');
        $hasBin->setAccessible(true);

        $this->assertFalse($hasBin->invoke($socket, ['hello', 'world']));
        $this->assertTrue($hasBin->invoke($socket, ["hello\x00world"]));
    }

    public function testCompressSetsFlag(): void
    {
        $nsp = $this->makeNsp();
        $client = $this->makeClient();
        $socket = new Socket($nsp, $client);

        $socket->compress(true)->emit('chat message', 'hi');

        // flags are consumed (and reset) by emit(); asserting no exception and
        // that the packet still went out is the observable contract here.
        $this->assertCount(1, $client->packetCalls);
    }
}
