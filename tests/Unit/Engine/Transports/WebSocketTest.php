<?php

namespace PHPSocketIO\Tests\Unit\Engine\Transports;

use PHPSocketIO\Engine\Transports\WebSocket;
use PHPUnit\Framework\TestCase;
use stdClass;

class FakeWsConnection
{
    public $onMessage;
    public $onClose;
    public $onError;
    public array $sendCalls = [];
    public array $closeCalls = [];

    public function send($data): void
    {
        $this->sendCalls[] = $data;
    }

    public function close(): void
    {
        $this->closeCalls[] = true;
    }
}

class WebSocketTest extends TestCase
{
    private function makeReq(): stdClass
    {
        $req = new stdClass();
        $req->connection = new FakeWsConnection();
        return $req;
    }

    public function testConstructWiresConnectionCallbacks(): void
    {
        $req = $this->makeReq();

        $transport = new WebSocket($req);

        $this->assertSame([$transport, 'onData2'], $req->connection->onMessage);
        $this->assertSame([$transport, 'onClose'], $req->connection->onClose);
        $this->assertSame([$transport, 'onError2'], $req->connection->onError);
    }

    public function testOnData2DecodesAndEmitsPacket(): void
    {
        $req = $this->makeReq();
        $transport = new WebSocket($req);
        $received = null;
        $transport->on('packet', function ($packet) use (&$received) {
            $received = $packet;
        });

        $transport->onData2($req->connection, '2');

        $this->assertSame(['type' => 'ping'], $received);
    }

    public function testSendWritesEncodedPacketsAndEmitsDrain(): void
    {
        $req = $this->makeReq();
        $transport = new WebSocket($req);
        $drainCount = 0;
        $transport->on('drain', function () use (&$drainCount) {
            $drainCount++;
        });

        $transport->send([['type' => 'message', 'data' => 'hi'], ['type' => 'ping']]);

        $this->assertSame(['4hi', '2'], $req->connection->sendCalls);
        $this->assertSame(2, $drainCount);
    }

    public function testSendIsNoopAfterDoClose(): void
    {
        $req = $this->makeReq();
        $transport = new WebSocket($req);
        $transport->doClose();

        $transport->send([['type' => 'ping']]);

        $this->assertSame([], $req->connection->sendCalls);
    }

    public function testDoCloseClosesSocketAndInvokesCallback(): void
    {
        $req = $this->makeReq();
        $transport = new WebSocket($req);
        $called = false;

        $transport->doClose(function () use (&$called) {
            $called = true;
        });

        $this->assertCount(1, $req->connection->closeCalls);
        $this->assertTrue($called);
        $this->assertNull($transport->socket);
    }

    public function testDoCloseIsIdempotent(): void
    {
        $req = $this->makeReq();
        $transport = new WebSocket($req);
        $transport->doClose();

        // second call must not error even though $socket is already null
        $transport->doClose();

        $this->assertCount(1, $req->connection->closeCalls);
    }
}
