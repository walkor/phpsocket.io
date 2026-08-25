<?php

namespace PHPSocketIO\Tests\Unit\Engine;

use PHPSocketIO\Engine\Socket;
use PHPSocketIO\Event\Emitter;
use PHPSocketIO\Tests\Unit\Fixtures\FakeEngineTransport;
use PHPUnit\Framework\TestCase;
use stdClass;

class SocketTest extends TestCase
{
    private function makeServer(): Emitter
    {
        $server = new class extends Emitter {
            public $upgradeTimeout = 5;
            public $pingInterval = 25;
            public $pingTimeout = 60;
        };
        return $server;
    }

    private function makeReq(): stdClass
    {
        $connection = new class {
            public function getRemoteIp()
            {
                return '127.0.0.1';
            }

            public function getRemotePort()
            {
                return 4000;
            }
        };
        $req = new stdClass();
        $req->connection = $connection;
        return $req;
    }

    private function makeSocket(?FakeEngineTransport $transport = null): array
    {
        $server = $this->makeServer();
        $transport = $transport ?? new FakeEngineTransport();
        $socket = new Socket('sid1', $server, $transport, $this->makeReq());
        return [$socket, $server, $transport];
    }

    public function testConstructSetsRemoteAddressAndOpensSocket(): void
    {
        [$socket, , $transport] = $this->makeSocket();

        $this->assertSame('127.0.0.1:4000', $socket->remoteAddress);
        $this->assertSame('open', $socket->readyState);
        $this->assertSame('sid1', $transport->sid);
        $this->assertCount(1, $transport->sendCalls);
        $this->assertSame('open', $transport->sendCalls[0][0]['type']);
    }

    public function testOnPacketRepliesPingWithPongAndEmitsHeartbeat(): void
    {
        [$socket, , $transport] = $this->makeSocket();
        $heartbeat = false;
        $socket->on('heartbeat', function () use (&$heartbeat) {
            $heartbeat = true;
        });

        $socket->onPacket(['type' => 'ping']);

        $this->assertTrue($heartbeat);
        $pongCall = end($transport->sendCalls);
        $this->assertSame('pong', $pongCall[0]['type']);
    }

    public function testOnPacketMessageEmitsDataAndMessageEvents(): void
    {
        [$socket] = $this->makeSocket();
        $dataSeen = null;
        $messageSeen = null;
        $socket->on('data', function ($d) use (&$dataSeen) {
            $dataSeen = $d;
        });
        $socket->on('message', function ($d) use (&$messageSeen) {
            $messageSeen = $d;
        });

        $socket->onPacket(['type' => 'message', 'data' => 'hi']);

        $this->assertSame('hi', $dataSeen);
        $this->assertSame('hi', $messageSeen);
    }

    public function testOnPacketErrorTypeClosesConnection(): void
    {
        [$socket] = $this->makeSocket();

        $socket->onPacket(['type' => 'error']);

        $this->assertSame('closed', $socket->readyState);
    }

    public function testOnPacketIgnoredWhenNotOpen(): void
    {
        [$socket] = $this->makeSocket();
        $socket->readyState = 'closed';
        $packetSeen = false;
        $socket->on('packet', function () use (&$packetSeen) {
            $packetSeen = true;
        });

        $socket->onPacket(['type' => 'message', 'data' => 'hi']);

        $this->assertFalse($packetSeen);
    }

    public function testSendPacketQueuesAndFlushesImmediatelyWhenWritable(): void
    {
        [$socket, , $transport] = $this->makeSocket();

        $socket->sendPacket('message', 'hello');

        $lastSend = end($transport->sendCalls);
        $this->assertSame(['type' => 'message', 'data' => 'hello'], $lastSend[0]);
        $this->assertSame([], $socket->writeBuffer);
    }

    public function testSendPacketDoesNotFlushWhenTransportNotWritable(): void
    {
        $transport = new FakeEngineTransport();
        [$socket] = $this->makeSocket($transport);
        $sendCallsBefore = count($transport->sendCalls);
        $transport->writable = false;

        $socket->sendPacket('message', 'hello');

        $this->assertCount($sendCallsBefore, $transport->sendCalls);
        $this->assertNotEmpty($socket->writeBuffer);
    }

    public function testFlushEmitsFlushAndDrainEvents(): void
    {
        $transport = new FakeEngineTransport();
        [$socket, $server] = $this->makeSocket($transport);
        $flushSeen = false;
        $drainSeen = false;
        $socket->on('flush', function () use (&$flushSeen) {
            $flushSeen = true;
        });
        $socket->on('drain', function () use (&$drainSeen) {
            $drainSeen = true;
        });
        $transport->writable = false;
        $socket->sendPacket('message', 'queued');
        $transport->writable = true;

        $socket->flush();

        $this->assertTrue($flushSeen);
        $this->assertTrue($drainSeen);
    }

    public function testCloseDefersToDrainWhenWriteBufferPending(): void
    {
        $transport = new FakeEngineTransport();
        $transport->writable = false;
        [$socket] = $this->makeSocket($transport);
        $socket->sendPacket('message', 'queued');

        $socket->close();

        $this->assertSame('closing', $socket->readyState);
        $this->assertCount(0, $transport->closeCalls);
    }

    public function testCloseClosesTransportImmediatelyWhenBufferEmpty(): void
    {
        [$socket, , $transport] = $this->makeSocket();

        $socket->close();

        $this->assertCount(1, $transport->closeCalls);
    }

    public function testOnCloseTearsDownStateAndEmitsClose(): void
    {
        [$socket, , $transport] = $this->makeSocket();
        $closeArgs = null;
        $socket->on('close', function (...$args) use (&$closeArgs) {
            $closeArgs = $args;
        });

        $socket->onClose('transport error', 'boom');

        $this->assertSame('closed', $socket->readyState);
        $this->assertSame(['sid1', 'transport error', 'boom'], $closeArgs);
        $this->assertNull($socket->server);
        $this->assertNull($socket->transport);
        $this->assertSame([], $transport->listeners('packet'));
    }

    public function testOnCloseIsIdempotent(): void
    {
        [$socket, , $transport] = $this->makeSocket();

        $socket->onClose('first');
        $socket->onClose('second');

        $this->assertCount(1, $transport->closeCalls);
    }

    public function testCheckSendsNoopWhenPollingAndWritable(): void
    {
        $transport = new FakeEngineTransport();
        $transport->name = 'polling';
        $transport->writable = true;
        [$socket] = $this->makeSocket($transport);

        $socket->check();

        $lastSend = end($transport->sendCalls);
        $this->assertSame('noop', $lastSend[0]['type']);
    }

    public function testCheckIsNoopForWebsocketTransport(): void
    {
        $transport = new FakeEngineTransport();
        $transport->name = 'websocket';
        [$socket] = $this->makeSocket($transport);
        $countBefore = count($transport->sendCalls);

        $socket->check();

        $this->assertCount($countBefore, $transport->sendCalls);
    }

    public function testOnErrorClosesWithTransportErrorReason(): void
    {
        [$socket] = $this->makeSocket();

        $socket->onError('boom');

        $this->assertSame('closed', $socket->readyState);
    }

    public function testGetAvailableUpgradesReturnsWebsocket(): void
    {
        [$socket] = $this->makeSocket();

        $this->assertSame(['websocket'], $socket->getAvailableUpgrades());
    }

    public function testMaybeUpgradeWiresUpgradeTransportListeners(): void
    {
        [$socket] = $this->makeSocket();
        $upgradeTransport = new FakeEngineTransport();

        $socket->maybeUpgrade($upgradeTransport);

        $this->assertTrue($socket->upgrading);
        $this->assertSame($upgradeTransport, $socket->upgradeTransport);
        $this->assertNotEmpty($upgradeTransport->listeners('packet'));
    }

    public function testOnUpgradePacketProbeSendsPongProbeOnUpgradeTransport(): void
    {
        [$socket] = $this->makeSocket();
        $upgradeTransport = new FakeEngineTransport();
        $socket->maybeUpgrade($upgradeTransport);

        $socket->onUpgradePacket(['type' => 'ping', 'data' => 'probe']);

        $lastSend = end($upgradeTransport->sendCalls);
        $this->assertSame('pong', $lastSend[0]['type']);
        $this->assertSame('probe', $lastSend[0]['data']);
    }

    public function testOnUpgradePacketUpgradeTypeSwapsActiveTransport(): void
    {
        [$socket, , $originalTransport] = $this->makeSocket();
        $upgradeTransport = new FakeEngineTransport();
        $socket->maybeUpgrade($upgradeTransport);

        $socket->onUpgradePacket(['type' => 'upgrade']);

        $this->assertTrue($socket->upgraded);
        $this->assertSame($upgradeTransport, $socket->transport);
        $this->assertCount(1, $originalTransport->destroyCalls);
        $this->assertNull($socket->upgradeTransport);
    }
}
