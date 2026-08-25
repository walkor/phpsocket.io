<?php

namespace PHPSocketIO\Tests\Unit;

use PHPSocketIO\ChannelAdapter;
use PHPSocketIO\Parser\Encoder;
use PHPSocketIO\Tests\Unit\Fixtures\RecordingSocket;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
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
 * integration-test territory. What IS pure, self-contained logic is
 * onChannelMessage() (deciding whether an incoming pub/sub message came
 * from this same process and whether to re-broadcast it locally), so that's
 * built here via ReflectionClass::newInstanceWithoutConstructor() to avoid
 * ever touching the real constructor's network calls.
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
