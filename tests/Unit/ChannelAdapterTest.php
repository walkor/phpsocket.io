<?php

namespace PHPSocketIO\Tests\Unit;

use PHPSocketIO\ChannelAdapter;
use PHPSocketIO\Parser\Encoder;
use PHPSocketIO\Tests\Unit\Fixtures\RecordingSocket;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

class FakeChannelNsp
{
    public string $name = '/';
    public array $connected = [];
}

/**
 * ChannelAdapter's constructor and add()/del()/delAll()/broadcast()'s
 * "publish to other processes" path all call the real \Channel\Client
 * (a TCP connection to a workerman/channel server), which isn't something
 * this suite can safely exercise without a running channel server -- that's
 * integration-test territory (see tests/integration/channel-adapter/).
 * What IS pure, self-contained logic: onChannelMessage() (deciding whether
 * an incoming pub/sub message came from this same process and whether to
 * re-broadcast it locally), isSidRoom()/resolveChannelsForRooms() (deciding
 * which channel(s) a broadcast should publish to), and add()/del()/delAll()
 * specifically for sid-shaped rooms, which return before ever reaching
 * \Channel\Client. Built via ReflectionClass::newInstanceWithoutConstructor()
 * to avoid ever touching the real constructor's network calls.
 */
class ChannelAdapterTest extends TestCase
{
    private function makeAdapter(string $channelId, FakeChannelNsp $nsp): ChannelAdapter
    {
        $adapter = (new ReflectionClass(ChannelAdapter::class))->newInstanceWithoutConstructor();

        $nspProp = new ReflectionProperty($adapter, 'nsp');
        $nspProp->setAccessible(true);
        $nspProp->setValue($adapter, $nsp);

        $encoderProp = new ReflectionProperty($adapter, 'encoder');
        $encoderProp->setAccessible(true);
        $encoderProp->setValue($adapter, new Encoder());

        $channelIdProp = new ReflectionProperty($adapter, '_channelId');
        $channelIdProp->setAccessible(true);
        $channelIdProp->setValue($adapter, $channelId);

        return $adapter;
    }

    /**
     * @param array<int, mixed> $args
     * @return mixed
     */
    private function callPrivateStatic(string $method, array $args)
    {
        $ref = new ReflectionMethod(ChannelAdapter::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    /**
     * @dataProvider sidRoomProvider
     */
    public function testIsSidRoomDetectsSocketIdsByShape(string $room, bool $expected): void
    {
        $this->assertSame($expected, $this->callPrivateStatic('isSidRoom', [$room]));
    }

    public static function sidRoomProvider(): array
    {
        return [
            'default namespace sid' => ['1bb9dcf2b5a3da4100aab5ee', true],
            'non-default namespace sid' => ['/admin#1bb9dcf2b5a3da4100aab5ee', true],
            'nested-looking namespace prefix' => ['/chat/room#1bb9dcf2b5a3da4100aab5ee', true],
            'short real room name' => ['room1', false],
            'empty room name' => ['', false],
            '23 hex chars (one short)' => ['1bb9dcf2b5a3da4100aab5e', false],
            '25 hex chars (one over)' => ['1bb9dcf2b5a3da4100aab5eee', false],
            '24 chars but not all hex' => ['not-a-real-sid-just-24ch', false],
            'uppercase hex never produced by bin2hex' => ['1BB9DCF2B5A3DA4100AAB5EE', false],
        ];
    }

    public function testResolveChannelsForRoomsUsesDedicatedChannelForRealRooms(): void
    {
        $channels = $this->callPrivateStatic('resolveChannelsForRooms', [['room1', 'room2']]);

        $this->assertSame(['socket.io#/#room1#', 'socket.io#/#room2#'], $channels);
    }

    public function testResolveChannelsForRoomsRoutesSidRoomToDefaultChannel(): void
    {
        $channels = $this->callPrivateStatic('resolveChannelsForRooms', [['1bb9dcf2b5a3da4100aab5ee']]);

        $this->assertSame(['socket.io#/#'], $channels);
    }

    public function testResolveChannelsForRoomsDedupesMultipleSidRoomsOntoOneDefaultPublish(): void
    {
        $channels = $this->callPrivateStatic('resolveChannelsForRooms', [[
            '1bb9dcf2b5a3da4100aab5ee',
            '2cc8ecf3c6b4eb5211bbc6ff',
        ]]);

        $this->assertSame(['socket.io#/#'], $channels);
    }

    public function testResolveChannelsForRoomsMixesSidAndRealRoomsCorrectly(): void
    {
        $channels = $this->callPrivateStatic('resolveChannelsForRooms', [[
            'room1',
            '1bb9dcf2b5a3da4100aab5ee',
            'room2',
        ]]);

        $this->assertSame(['socket.io#/#room1#', 'socket.io#/#', 'socket.io#/#room2#'], $channels);
    }

    public function testAddOnSidRoomRegistersLocallyWithoutTouchingTheNetwork(): void
    {
        $nsp = new FakeChannelNsp();
        $adapter = $this->makeAdapter('self-id', $nsp);
        $sid = '1bb9dcf2b5a3da4100aab5ee';

        // add() returns before reaching \Channel\Client::subscribe() for a
        // sid-room, so this never touches the network -- if it did, this
        // call would hang/throw since no real channel server is running.
        $adapter->add($sid, $sid);

        $this->assertTrue(isset($adapter->sids[$sid][$sid]));
        $this->assertTrue(isset($adapter->rooms[$sid][$sid]));
    }

    public function testDelOnSidRoomUnregistersLocallyWithoutTouchingTheNetwork(): void
    {
        $nsp = new FakeChannelNsp();
        $adapter = $this->makeAdapter('self-id', $nsp);
        $sid = '1bb9dcf2b5a3da4100aab5ee';
        $adapter->add($sid, $sid);

        $adapter->del($sid, $sid);

        $this->assertArrayNotHasKey($sid, $adapter->rooms);
    }

    public function testDelAllOnSidRoomUnregistersLocallyWithoutTouchingTheNetwork(): void
    {
        $nsp = new FakeChannelNsp();
        $adapter = $this->makeAdapter('self-id', $nsp);
        $sid = '1bb9dcf2b5a3da4100aab5ee';
        $adapter->add($sid, $sid);

        $adapter->delAll($sid);

        $this->assertArrayNotHasKey($sid, $adapter->sids);
        $this->assertArrayNotHasKey($sid, $adapter->rooms);
    }

    public function testOnChannelMessageIgnoresMessagesFromItself(): void
    {
        $nsp = new FakeChannelNsp();
        $target = new RecordingSocket();
        $nsp->connected['target'] = $target;
        $adapter = $this->makeAdapter('self-id', $nsp);
        $adapter->sids['target'] = ['target' => true];

        $adapter->onChannelMessage('socket.io#/#', ['self-id', ['type' => 2], []]);

        $this->assertCount(0, $target->packetCalls);
    }

    public function testOnChannelMessageBroadcastsMessagesFromOtherProcesses(): void
    {
        $nsp = new FakeChannelNsp();
        $target = new RecordingSocket();
        $nsp->connected['target'] = $target;
        $adapter = $this->makeAdapter('self-id', $nsp);
        $adapter->sids['target'] = ['target' => true];

        $adapter->onChannelMessage('socket.io#/#', ['other-id', ['type' => 2, 'data' => ['hi']], []]);

        $this->assertCount(1, $target->packetCalls);
    }

    public function testOnChannelMessageIgnoresMismatchedNamespace(): void
    {
        $nsp = new FakeChannelNsp();
        $nsp->name = '/chat';
        $target = new RecordingSocket();
        $nsp->connected['target'] = $target;
        $adapter = $this->makeAdapter('self-id', $nsp);
        $adapter->sids['target'] = ['target' => true];

        $adapter->onChannelMessage('socket.io#/#', ['other-id', ['type' => 2, 'nsp' => '/other'], []]);

        $this->assertCount(0, $target->packetCalls);
    }

    public function testOnChannelMessageDefaultsMissingNamespaceToRoot(): void
    {
        $nsp = new FakeChannelNsp();
        $nsp->name = '/';
        $target = new RecordingSocket();
        $nsp->connected['target'] = $target;
        $adapter = $this->makeAdapter('self-id', $nsp);
        $adapter->sids['target'] = ['target' => true];

        $adapter->onChannelMessage('socket.io#/#', ['other-id', ['type' => 2, 'data' => ['hi']], []]);

        $this->assertCount(1, $target->packetCalls);
    }

    public function testOnChannelMessageIgnoresEmptyPacket(): void
    {
        $nsp = new FakeChannelNsp();
        $target = new RecordingSocket();
        $nsp->connected['target'] = $target;
        $adapter = $this->makeAdapter('self-id', $nsp);
        $adapter->sids['target'] = ['target' => true];

        $adapter->onChannelMessage('socket.io#/#', ['other-id', null, []]);

        $this->assertCount(0, $target->packetCalls);
    }
}
