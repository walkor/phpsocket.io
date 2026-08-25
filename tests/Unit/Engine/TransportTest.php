<?php

namespace PHPSocketIO\Tests\Unit\Engine;

use PHPSocketIO\Engine\Transport;
use PHPUnit\Framework\TestCase;

class TransportTest extends TestCase
{
    private function makeTransport(): Transport
    {
        // Transport::close() calls $this->doClose(), which only concrete
        // transports (Polling, WebSocket) implement.
        return new class extends Transport {
            public array $doCloseCalls = [];

            public function doClose(callable $fn): void
            {
                $this->doCloseCalls[] = true;
                $fn();
            }
        };
    }

    public function testOnRequestStoresRequest(): void
    {
        $transport = $this->makeTransport();
        $req = new \stdClass();

        $transport->onRequest($req);

        $this->assertSame($req, $transport->req);
    }

    public function testCloseTransitionsReadyStateAndCallsDoClose(): void
    {
        $transport = $this->makeTransport();
        $called = false;

        $transport->close(function () use (&$called) {
            $called = true;
        });

        $this->assertSame('closing', $transport->readyState);
        $this->assertTrue($called);
        $this->assertCount(1, $transport->doCloseCalls);
    }

    public function testCloseDefaultsToNoopCallback(): void
    {
        $transport = $this->makeTransport();

        $transport->close();

        $this->assertCount(1, $transport->doCloseCalls);
    }

    public function testOnErrorEmitsWhenListenerRegistered(): void
    {
        $transport = $this->makeTransport();
        $received = null;
        $transport->on('error', function ($msg) use (&$received) {
            $received = $msg;
        });

        $transport->onError('boom', 'detail');

        $this->assertSame('TransportError: boom - detail', $received);
    }

    public function testOnErrorWithoutListenerDoesNotThrow(): void
    {
        $transport = $this->makeTransport();

        $transport->onError('boom');

        $this->addToAssertionCount(1); // reaching here means it didn't throw
    }

    public function testOnPacketEmitsPacketEvent(): void
    {
        $transport = $this->makeTransport();
        $received = null;
        $transport->on('packet', function ($packet) use (&$received) {
            $received = $packet;
        });

        $transport->onPacket(['type' => 'message', 'data' => 'hi']);

        $this->assertSame(['type' => 'message', 'data' => 'hi'], $received);
    }

    public function testOnCloseResetsStateEmitsCloseAndRemovesListeners(): void
    {
        $transport = $this->makeTransport();
        $transport->req = new \stdClass();
        $transport->res = new \stdClass();
        $closeEmitted = false;
        $transport->on('close', function () use (&$closeEmitted) {
            $closeEmitted = true;
        });

        $transport->onClose();

        $this->assertNull($transport->req);
        $this->assertNull($transport->res);
        $this->assertSame('closed', $transport->readyState);
        $this->assertTrue($closeEmitted);
        $this->assertSame([], $transport->listeners('close'));
    }

    public function testDestroyResetsState(): void
    {
        $transport = $this->makeTransport();
        $transport->req = new \stdClass();
        $transport->res = new \stdClass();
        $transport->shouldClose = function () {
        };

        $transport->destroy();

        $this->assertNull($transport->req);
        $this->assertNull($transport->res);
        $this->assertNull($transport->shouldClose);
        $this->assertSame('closed', $transport->readyState);
    }
}
