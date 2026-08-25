<?php

namespace PHPSocketIO\Tests\Unit\Fixtures;

/**
 * Stands in for a PHPSocketIO\Socket entry in Nsp::$connected /
 * DefaultAdapter's room bookkeeping. The adapter hands this already-encoded
 * packets (it calls Socket::packet(), which forwards to Client::packet()),
 * so this only needs to record what it was given -- decode with
 * PHPSocketIO\Parser\Decoder in the test to assert on the payload.
 */
class RecordingSocket
{
    public array $packetCalls = [];

    public function packet(...$args): void
    {
        $this->packetCalls[] = $args;
    }
}
