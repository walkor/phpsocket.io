<?php

namespace PHPSocketIO\Tests\Unit\Fixtures;

use PHPSocketIO\Event\Emitter;
use stdClass;

/**
 * Stands in for the Engine\Socket that PHPSocketIO\Client wraps as $conn.
 * Extends the real Emitter so Client::setup()'s on('data'/'error'/'close')
 * wiring behaves exactly as it does with a real Engine\Socket.
 */
class FakeEngineConn extends Emitter
{
    public $id;
    public $request;
    public string $readyState = 'open';
    public string $remoteAddress = '127.0.0.1:1234';
    public $transport;
    public array $writeCalls = [];
    public array $closeCalls = [];

    public static function make(string $id = 'conn1'): self
    {
        $conn = new self();
        $conn->id = $id;
        $conn->request = new stdClass();
        $conn->transport = new stdClass();
        $conn->transport->writable = true;
        return $conn;
    }

    public function write($data): void
    {
        $this->writeCalls[] = $data;
    }

    public function close(): void
    {
        $this->closeCalls[] = true;
        $this->readyState = 'closed';
    }
}
