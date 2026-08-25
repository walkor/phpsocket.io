<?php

namespace PHPSocketIO\Tests\Unit;

use PHPSocketIO\Client;
use PHPSocketIO\Nsp;
use PHPSocketIO\SocketIO;
use PHPSocketIO\Tests\Unit\Fixtures\FakeEngineConn;
use PHPSocketIO\Tests\Unit\Fixtures\InertAdapter;
use PHPSocketIO\Tests\Unit\Fixtures\RecordingSocket;
use PHPUnit\Framework\TestCase;
use stdClass;

class SocketIOTest extends TestCase
{
    public function testConstructRegistersDefaultsForNspSocketAndAdapter(): void
    {
        $io = new SocketIO();

        $this->assertSame('\PHPSocketIO\Nsp', $io->nsp());
        $this->assertSame('\PHPSocketIO\Socket', $io->socket());
        $this->assertSame('\PHPSocketIO\DefaultAdapter', $io->adapter());
        $this->assertInstanceOf(Nsp::class, $io->sockets);
        $this->assertSame('/', $io->sockets->name);
    }

    public function testConstructAppliesCustomClassOptions(): void
    {
        $io = new SocketIO(null, ['adapter' => InertAdapter::class]);

        $this->assertSame(InertAdapter::class, $io->adapter());
        $this->assertInstanceOf(InertAdapter::class, $io->sockets->adapter);
    }

    public function testConstructAppliesOriginsOption(): void
    {
        $io = new SocketIO(null, ['origins' => 'https://example.com']);

        $this->assertSame('https://example.com', $io->origins());
    }

    public function testOfCreatesAndCachesNamespace(): void
    {
        $io = new SocketIO();

        $first = $io->of('/chat');
        $second = $io->of('/chat');

        $this->assertSame($first, $second);
        $this->assertArrayHasKey('/chat', $io->nsps);
    }

    public function testOfNormalizesNameWithoutLeadingSlash(): void
    {
        $io = new SocketIO();

        $nsp = $io->of('chat');

        $this->assertSame('/chat', $nsp->name);
    }

    public function testOfRegistersConnectListenerWhenCallbackGiven(): void
    {
        $io = new SocketIO();
        $seen = false;

        $io->of('/chat', function () use (&$seen) {
            $seen = true;
        });
        $io->nsps['/chat']->emit('connect', new stdClass());

        $this->assertTrue($seen);
    }

    public function testAdapterChangeReinitializesExistingNamespaces(): void
    {
        $io = new SocketIO();
        $chat = $io->of('/chat');
        $originalAdapter = $chat->adapter;

        $io->adapter('\PHPSocketIO\DefaultAdapter');

        $this->assertNotSame($originalAdapter, $chat->adapter);
        $this->assertInstanceOf(\PHPSocketIO\DefaultAdapter::class, $chat->adapter);
    }

    public function testOnConnectionCreatesClientAndJoinsDefaultNamespace(): void
    {
        $io = new SocketIO();
        $conn = FakeEngineConn::make('c1');

        $io->onConnection($conn);

        $this->assertArrayHasKey('c1', $io->sockets->sockets);
    }

    public function testOnDelegatesGenericEventsToDefaultNamespace(): void
    {
        $io = new SocketIO();
        $seen = null;
        $io->on('connection', function ($x) use (&$seen) {
            $seen = $x;
        });

        $io->sockets->emit('connection', 'socket-placeholder');

        $this->assertSame('socket-placeholder', $seen);
    }

    public function testOnWiresWorkerStartAndStopSeparatelyFromWorker(): void
    {
        $io = new SocketIO();
        $io->worker = new stdClass();
        $startFn = function () {
        };
        $stopFn = function () {
        };

        $io->on('workerStart', $startFn);
        $io->on('workerStop', $stopFn);

        $this->assertSame($startFn, $io->worker->onWorkerStart);
        $this->assertSame($stopFn, $io->worker->onWorkerStop);
    }

    public function testToDelegatesToDefaultNamespace(): void
    {
        $io = new SocketIO();

        $io->to('room1');

        $this->assertArrayHasKey('room1', $io->sockets->rooms);
    }

    public function testInDelegatesToDefaultNamespace(): void
    {
        $io = new SocketIO();

        $io->in('room1');

        $this->assertArrayHasKey('room1', $io->sockets->rooms);
    }

    public function testEmitDelegatesToDefaultNamespace(): void
    {
        $io = new SocketIO();
        $target = new RecordingSocket();
        $io->sockets->connected['target'] = $target;
        $io->sockets->adapter->add('target', 'target');

        $io->emit('chat message', 'hi');

        $this->assertCount(1, $target->packetCalls);
    }

    public function testSendDelegatesToDefaultNamespace(): void
    {
        $io = new SocketIO();
        $target = new RecordingSocket();
        $io->sockets->connected['target'] = $target;
        $io->sockets->adapter->add('target', 'target');

        $io->send('hello');

        $this->assertCount(1, $target->packetCalls);
    }

    public function testWriteDelegatesToDefaultNamespace(): void
    {
        $io = new SocketIO();
        $target = new RecordingSocket();
        $io->sockets->connected['target'] = $target;
        $io->sockets->adapter->add('target', 'target');

        $io->write('hello');

        $this->assertCount(1, $target->packetCalls);
    }
}
