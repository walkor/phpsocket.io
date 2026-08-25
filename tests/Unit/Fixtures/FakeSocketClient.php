<?php

namespace PHPSocketIO\Tests\Unit\Fixtures;

use stdClass;

/**
 * Minimal stand-in for PHPSocketIO\Client, just enough surface for
 * Socket/Nsp to construct against and for tests to inspect what was sent.
 */
class FakeSocketClient
{
    public $id;
    public $request;
    public $conn;
    public array $packetCalls = [];
    public array $disconnectCalls = [];

    public static function make(string $id = 'client1'): self
    {
        $client = new self();
        $client->id = $id;

        $request = new stdClass();
        $request->url = '/socket.io/';
        $request->headers = [];
        $request->connection = new stdClass();
        $request->connection->encrypted = false;
        $client->request = $request;

        $conn = new stdClass();
        $conn->remoteAddress = '127.0.0.1:1234';
        $conn->readyState = 'open';
        $client->conn = $conn;

        return $client;
    }

    public function packet($packet, $preEncoded = false, $volatile = false): void
    {
        $this->packetCalls[] = [$packet, $preEncoded, $volatile];
    }

    public function disconnect(): void
    {
        $this->disconnectCalls[] = true;
    }

    public function remove($socket): void
    {
    }
}
