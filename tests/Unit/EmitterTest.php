<?php

namespace PHPSocketIO\Tests\Unit;

use PHPSocketIO\Event\Emitter;
use PHPUnit\Framework\TestCase;

class EmitterTest extends TestCase
{
    public function testOnRegistersListenerAndEmitCallsIt(): void
    {
        $emitter = new Emitter();
        $received = [];

        $emitter->on('test', function ($arg1, $arg2) use (&$received) {
            $received[] = [$arg1, $arg2];
        });

        $emitter->emit('test', 1, 2);

        $this->assertSame([[1, 2]], $received);
    }

    public function testOnAllowsMultipleListenersForSameEvent(): void
    {
        $emitter = new Emitter();
        $calls = 0;

        $emitter->on('test', function () use (&$calls) {
            $calls++;
        });
        $emitter->on('test', function () use (&$calls) {
            $calls++;
        });
        $emitter->emit('test');

        $this->assertSame(2, $calls);
    }

    public function testOnceListenerFiresOnlyOnce(): void
    {
        $emitter = new Emitter();
        $calls = 0;

        $emitter->once('test', function () use (&$calls) {
            $calls++;
        });

        $emitter->emit('test');
        $emitter->emit('test');

        $this->assertSame(1, $calls);
    }

    public function testRemoveListenerStopsFutureCalls(): void
    {
        $emitter = new Emitter();
        $calls = 0;
        $listener = function () use (&$calls) {
            $calls++;
        };

        $emitter->on('test', $listener);
        $emitter->emit('test');
        $emitter->removeListener('test', $listener);
        $emitter->emit('test');

        $this->assertSame(1, $calls);
    }

    public function testRemoveAllListenersForSpecificEvent(): void
    {
        $emitter = new Emitter();
        $calls = 0;

        $emitter->on('test', function () use (&$calls) {
            $calls++;
        });
        $emitter->on('other', function () use (&$calls) {
            $calls++;
        });

        $emitter->removeAllListeners('test');
        $emitter->emit('test');
        $emitter->emit('other');

        $this->assertSame(1, $calls);
    }

    public function testRemoveAllListenersWithoutEventNameClearsEverything(): void
    {
        $emitter = new Emitter();
        $calls = 0;

        $emitter->on('test', function () use (&$calls) {
            $calls++;
        });
        $emitter->on('other', function () use (&$calls) {
            $calls++;
        });

        $emitter->removeAllListeners();
        $emitter->emit('test');
        $emitter->emit('other');

        $this->assertSame(0, $calls);
    }

    public function testListenersReturnsRegisteredCallbacks(): void
    {
        $emitter = new Emitter();
        $listener = function () {
        };

        $this->assertSame([], $emitter->listeners('test'));

        $emitter->on('test', $listener);

        $this->assertSame([$listener], $emitter->listeners('test'));
    }

    public function testEmitReturnsFalseWhenNoListeners(): void
    {
        $emitter = new Emitter();

        $this->assertFalse($emitter->emit('test'));
    }

    public function testEmitReturnsTrueWhenListenersRan(): void
    {
        $emitter = new Emitter();
        $emitter->on('test', function () {
        });

        $this->assertTrue($emitter->emit('test'));
    }

    public function testOnEmitsNewListenerEvent(): void
    {
        $emitter = new Emitter();
        $seen = [];

        $emitter->on('newListener', function ($eventName) use (&$seen) {
            $seen[] = $eventName;
        });
        $emitter->on('test', function () {
        });

        $this->assertSame(['test'], $seen);
    }

    public function testRemoveListenerEmitsRemoveListenerEvent(): void
    {
        $emitter = new Emitter();
        $seen = [];
        $listener = function () {
        };

        $emitter->on('removeListener', function ($eventName) use (&$seen) {
            $seen[] = $eventName;
        });
        $emitter->on('test', $listener);
        $emitter->removeListener('test', $listener);

        $this->assertSame(['test'], $seen);
    }

    public function testOnReturnsEmitterForChaining(): void
    {
        $emitter = new Emitter();

        $this->assertSame($emitter, $emitter->on('test', function () {
        }));
        $this->assertSame($emitter, $emitter->once('test', function () {
        }));
        $this->assertSame($emitter, $emitter->removeListener('test', function () {
        }));
        $this->assertSame($emitter, $emitter->removeAllListeners());
    }
}
