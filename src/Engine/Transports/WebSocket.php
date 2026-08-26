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

    // Adapts Workerman's ($connection, $data) onMessage callback to Transport::onData($data).
    public function onConnectionMessage(object $connection, string $data): void
    {
        parent::onData($data);
    }

    // Adapts Workerman's ($connection, $code, $msg) onError callback to Transport::onError().
    public function onConnectionError(object $connection, int $code, string $msg): void
    {
        parent::onError($msg, (string)$code);
    }

    /**
     * @param array<int, array<string, mixed>> $packets
     */
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
