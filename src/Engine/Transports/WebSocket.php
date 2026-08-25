<?php

namespace PHPSocketIO\Engine\Transports;

use PHPSocketIO\Engine\Transport;
use PHPSocketIO\Engine\Parser;

class WebSocket extends Transport
{
    public ?string $sid = null;
    public bool $writable = true;
    public bool $supportsFraming = true;
    public bool $supportsBinary = true;
    public string $name = 'websocket';
    public ?object $socket = null;

    public function __construct(object $req)
    {
        $this->socket = $req->connection;
        $this->socket->onMessage = [$this, 'onConnectionMessage'];
        $this->socket->onClose = [$this, 'onClose'];
        $this->socket->onError = [$this, 'onConnectionError'];
    }

    /**
     * Adapter for the raw connection's onMessage callback, which Workerman
     * always invokes as ($connection, $data). Drops $connection and
     * forwards to the real Transport::onData(), which doesn't take one.
     */
    public function onConnectionMessage(object $connection, string $data): void
    {
        call_user_func([get_parent_class($this), 'onData'], $data);
    }

    /**
     * Adapter for the raw connection's onError callback, invoked as
     * ($connection, $code, $msg). Forwards to Transport::onError(), which
     * emits 'error' -- the only way a raw transport-level failure reaches
     * Engine\Socket::onError()'s cleanup path.
     */
    public function onConnectionError(object $connection, int $code, string $msg): void
    {
        call_user_func([get_parent_class($this), 'onError'], $msg, (string)$code);
    }

    public function send(array $packets): void
    {
        foreach ($packets as $packet) {
            $data = Parser::encodePacket($packet);
            if ($this->socket) {
                $this->socket->send($data);
                $this->emit('drain');
            }
        }
    }

    public function doClose(?callable $fn = null): void
    {
        if ($this->socket) {
            $this->socket->close();
            $this->socket = null;
            if (! empty($fn)) {
                call_user_func($fn);
            }
        }
    }
}
