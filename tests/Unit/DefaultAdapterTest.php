<?php

namespace PHPSocketIO\Tests\Unit;

use PHPSocketIO\DefaultAdapter;
use PHPUnit\Framework\TestCase;

class FakeAdapterSocket
{
    public array $packetCalls = [];

    public function packet(...$args): void
    {
        $this->packetCalls[] = $args;
    }
}

class FakeAdapterNsp
{
    public string $name = '/';
    public array $connected = [];
}

class DefaultAdapterTest extends TestCase
{
    public function testAddTracksRoomAndSidBookkeeping(): void
    {
        $adapter = new DefaultAdapter(new FakeAdapterNsp());

        $adapter->add('sid1', 'room1');

        $this->assertTrue($adapter->rooms['room1']['sid1']);
        $this->assertTrue($adapter->sids['sid1']['room1']);
    }

    public function testDelRemovesSidFromRoomAndDropsEmptyRoom(): void
    {
        $adapter = new DefaultAdapter(new FakeAdapterNsp());
        $adapter->add('sid1', 'room1');

        $adapter->del('sid1', 'room1');

        $this->assertArrayNotHasKey('sid1', $adapter->rooms['room1'] ?? []);
        $this->assertArrayNotHasKey('room1', $adapter->rooms);
        $this->assertArrayNotHasKey('room1', $adapter->sids['sid1']);
    }

    public function testDelAllRemovesSidFromEveryJoinedRoom(): void
    {
        $adapter = new DefaultAdapter(new FakeAdapterNsp());
        $adapter->add('sid1', 'room1');
        $adapter->add('sid1', 'room2');

        $adapter->delAll('sid1');

        $this->assertArrayNotHasKey('room1', $adapter->rooms);
        $this->assertArrayNotHasKey('room2', $adapter->rooms);
        $this->assertArrayNotHasKey('sid1', $adapter->sids);
    }

    public function testBroadcastToRoomOnlyReachesRoomMembers(): void
    {
        $nsp = new FakeAdapterNsp();
        $inRoom = new FakeAdapterSocket();
        $outOfRoom = new FakeAdapterSocket();
        $nsp->connected['sid-in'] = $inRoom;
        $nsp->connected['sid-out'] = $outOfRoom;

        $adapter = new DefaultAdapter($nsp);
        $adapter->add('sid-in', 'room1');

        $adapter->broadcast(['type' => 2, 'data' => ['hi']], ['rooms' => ['room1']]);

        $this->assertCount(1, $inRoom->packetCalls);
        $this->assertCount(0, $outOfRoom->packetCalls);
    }

    public function testBroadcastToRoomSkipsExceptedSids(): void
    {
        $nsp = new FakeAdapterNsp();
        $sender = new FakeAdapterSocket();
        $other = new FakeAdapterSocket();
        $nsp->connected['sender'] = $sender;
        $nsp->connected['other'] = $other;

        $adapter = new DefaultAdapter($nsp);
        $adapter->add('sender', 'room1');
        $adapter->add('other', 'room1');

        $adapter->broadcast(['type' => 2, 'data' => ['hi']], ['rooms' => ['room1'], 'except' => ['sender' => 'sender']]);

        $this->assertCount(0, $sender->packetCalls);
        $this->assertCount(1, $other->packetCalls);
    }

    public function testBroadcastWithoutRoomsReachesAllKnownSids(): void
    {
        $nsp = new FakeAdapterNsp();
        $a = new FakeAdapterSocket();
        $b = new FakeAdapterSocket();
        $nsp->connected['a'] = $a;
        $nsp->connected['b'] = $b;

        $adapter = new DefaultAdapter($nsp);
        // sids are only known once a socket has joined at least one room
        // (its own id room, in the real Socket::onconnect flow)
        $adapter->add('a', 'a');
        $adapter->add('b', 'b');

        $adapter->broadcast(['type' => 2, 'data' => ['hi']], []);

        $this->assertCount(1, $a->packetCalls);
        $this->assertCount(1, $b->packetCalls);
    }

    public function testClientsPassesRoomSidsToCallback(): void
    {
        $adapter = new DefaultAdapter(new FakeAdapterNsp());
        $adapter->add('sid1', 'room1');
        $adapter->add('sid2', 'room1');
        $adapter->add('sid3', 'room2');

        $received = null;
        $adapter->clients(['room1'], function ($sids) use (&$received) {
            $received = $sids;
        });

        sort($received);
        $this->assertSame(['sid1', 'sid2'], $received);
    }

    public function testClientsWithUnknownRoomPassesEmptyList(): void
    {
        $adapter = new DefaultAdapter(new FakeAdapterNsp());

        $received = 'not-called';
        $adapter->clients(['no-such-room'], function ($sids) use (&$received) {
            $received = $sids;
        });

        $this->assertSame([], $received);
    }
}
