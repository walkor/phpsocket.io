<?php

namespace PHPSocketIO\Tests\Unit;

use PHPSocketIO\Parser\Decoder;
use PHPSocketIO\SocketIO;
use PHPSocketIO\Tests\Unit\Fixtures\FakeSocketClient;
use PHPSocketIO\Tests\Unit\Fixtures\RecordingSocket;
use PHPUnit\Framework\TestCase;

class NspTest extends TestCase
{
    private function decodeFirstPacket(RecordingSocket $target): array
    {
        [$encodedPackets] = $target->packetCalls[0];
        return (new Decoder())->decodeString($encodedPackets[0]);
    }

    public function testToTracksRoomName(): void
    {
        $nsp = (new SocketIO())->of('/');

        $nsp->to('room1');

        $this->assertArrayHasKey('room1', $nsp->getRoomTargets());
    }

    public function testInIsAliasForTo(): void
    {
        $nsp = (new SocketIO())->of('/');

        $nsp->in('room1');

        $this->assertArrayHasKey('room1', $nsp->getRoomTargets());
    }

    public function testEmitBroadcastsToConnectedSocketsInTargetedRoom(): void
    {
        $nsp = (new SocketIO())->of('/');
        $target = new RecordingSocket();
        $nsp->connected['target'] = $target;
        $nsp->adapter->add('target', 'room1');

        $nsp->to('room1')->emit('chat message', 'hi');

        $this->assertCount(1, $target->packetCalls);
        $this->assertSame(['chat message', 'hi'], $this->decodeFirstPacket($target)['data']);
    }

    public function testEmitResetsRoomsAndFlagsAfterBroadcast(): void
    {
        $nsp = (new SocketIO())->of('/');
        $nsp->to('room1')->compress(true);

        $nsp->emit('chat message', 'hi');

        $this->assertSame([], $nsp->getRoomTargets());
        $this->assertSame([], $nsp->getFlags());
    }

    public function testEmitWithTrailingCallableDoesNotBroadcast(): void
    {
        $nsp = (new SocketIO())->of('/');
        $target = new RecordingSocket();
        $nsp->connected['target'] = $target;
        $nsp->adapter->add('target', 'room1');

        $nsp->to('room1')->emit('chat message', 'hi', function () {
        });

        $this->assertCount(0, $target->packetCalls);
    }

    public function testSendPrependsMessageEventAndBroadcastsFlatArgs(): void
    {
        $nsp = (new SocketIO())->of('/');
        $target = new RecordingSocket();
        $nsp->connected['target'] = $target;
        $nsp->adapter->add('target', 'target');

        $nsp->send('hello');

        $this->assertCount(1, $target->packetCalls);
        $this->assertSame(['message', 'hello'], $this->decodeFirstPacket($target)['data']);
    }

    public function testWriteDelegatesToSend(): void
    {
        $nsp = (new SocketIO())->of('/');
        $target = new RecordingSocket();
        $nsp->connected['target'] = $target;
        $nsp->adapter->add('target', 'target');

        $nsp->write('hello');

        $this->assertSame(['message', 'hello'], $this->decodeFirstPacket($target)['data']);
    }

    public function testCompressSetsFlag(): void
    {
        $nsp = (new SocketIO())->of('/');

        $nsp->compress(true);

        $this->assertTrue($nsp->getFlags()['compress']);
    }

    public function testClientsDelegatesToAdapterWithTargetedRooms(): void
    {
        $nsp = (new SocketIO())->of('/');
        $nsp->adapter->add('sid1', 'room1');
        $nsp->adapter->add('sid2', 'room1');
        $nsp->to('room1');

        $received = null;
        $nsp->clients(function ($sids) use (&$received) {
            $received = $sids;
        });

        sort($received);
        $this->assertSame(['sid1', 'sid2'], $received);
    }

    public function testAddRegistersSocketAndEmitsConnectAndConnection(): void
    {
        $nsp = (new SocketIO())->of('/');
        $client = FakeSocketClient::make('c1');

        $connectSeen = null;
        $connectionSeen = null;
        $nsp->on('connect', function ($socket) use (&$connectSeen) {
            $connectSeen = $socket;
        });
        $nsp->on('connection', function ($socket) use (&$connectionSeen) {
            $connectionSeen = $socket;
        });

        $fnCalledWith = null;
        $nsp->add($client, $nsp, function ($socket, $ns) use (&$fnCalledWith) {
            $fnCalledWith = [$socket, $ns];
        });

        $this->assertArrayHasKey('c1', $nsp->sockets);
        $this->assertSame($nsp->sockets['c1'], $connectSeen);
        $this->assertSame($nsp->sockets['c1'], $connectionSeen);
        $this->assertSame($nsp->sockets['c1'], $fnCalledWith[0]);
    }

    public function testAddIgnoresClientWithClosedConnection(): void
    {
        $nsp = (new SocketIO())->of('/');
        $client = FakeSocketClient::make('c1');
        $client->conn->readyState = 'closed';

        $nsp->add($client, $nsp, function () {
            $this->fail('callback should not run for a closed connection');
        });

        $this->assertArrayNotHasKey('c1', $nsp->sockets);
    }

    public function testRemoveUnregistersSocket(): void
    {
        $nsp = (new SocketIO())->of('/');
        $client = FakeSocketClient::make('c1');
        $nsp->add($client, $nsp, null);
        $socket = $nsp->sockets['c1'];

        $nsp->remove($socket);

        $this->assertArrayNotHasKey('c1', $nsp->sockets);
    }
}
