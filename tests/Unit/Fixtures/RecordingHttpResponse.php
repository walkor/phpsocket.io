<?php

namespace PHPSocketIO\Tests\Unit\Fixtures;

/**
 * Minimal stand-in for Engine\Protocols\Http\Response, for transports that
 * only need to know writeHead()/end() were called with what arguments.
 */
class RecordingHttpResponse
{
    public array $writeHeadCalls = [];
    public array $endCalls = [];

    public function writeHead(...$args): void
    {
        $this->writeHeadCalls[] = $args;
    }

    public function end(...$args): void
    {
        $this->endCalls[] = $args;
    }
}
