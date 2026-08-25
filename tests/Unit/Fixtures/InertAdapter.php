<?php

namespace PHPSocketIO\Tests\Unit\Fixtures;

/**
 * A harmless stand-in for a custom adapter class passed via SocketIO's
 * 'adapter' option -- unlike ChannelAdapter, it performs no real network
 * I/O, so it's safe to instantiate in a test.
 */
class InertAdapter
{
    public $nsp;

    public function __construct($nsp)
    {
        $this->nsp = $nsp;
    }
}
