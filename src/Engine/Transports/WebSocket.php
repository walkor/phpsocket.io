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
        $this->socket->onMessage = [$this, 'onData2'];
        $this->socket->onClose = [$this, 'onClose'];
        $this->socket->onError = [$this, 'onError2'];
    }

    public function onData2(object $connection, string $data): void
    {
        call_user_func([get_parent_class($this), 'onData'], $data);
    }

    public function onError2(object $connection, int $code, string $msg): void
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
