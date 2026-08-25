<?php

namespace PHPSocketIO\Tests\Unit\Fixtures;

use PHPSocketIO\Event\Emitter;

/**
 * Stands in for an Engine\Transports\* instance as Engine\Socket's
 * $transport/$upgradeTransport. Extends the real Emitter so
 * on()/once()/removeListener()/removeAllListeners() behave exactly as they
 * do with a real transport.
 */
class FakeEngineTransport extends Emitter
{
    public $sid;
    public string $readyState = 'open';
    public string $name = 'polling';
    public bool $writable = true;
    public bool $supportsFraming = true;
    public array $sendCalls = [];
    public array $closeCalls = [];
    public array $destroyCalls = [];

    public function send(array $packets): void
    {
        $this->sendCalls[] = $packets;
    }

    public function close(?callable $fn = null): void
    {
        if ('closed' === $this->readyState) {
            return;
        }
        $this->closeCalls[] = true;
        $this->readyState = 'closed';
        if ($fn) {
            $fn();
        }
    }

    public function destroy(): void
    {
        $this->destroyCalls[] = true;
    }
}
