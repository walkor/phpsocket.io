<?php

namespace PHPSocketIO\Engine\Transports;

use PHPSocketIO\Engine\Transport;
use PHPSocketIO\Engine\Parser;
use PHPSocketIO\Debug;

class WebSocket extends Transport
{
    public $sid = null;
    public $writable = true;
    public $supportsFraming = true;
    public $supportsBinary = true;
    public $name = 'websocket';
    public $socket = null;

    public function __construct($req)
    {
        $this->socket = $req->connection;
        $this->socket->onMessage = [$this, 'onData2'];
        $this->socket->onClose = [$this, 'onClose'];
        $this->socket->onError = [$this, 'onError2'];
        Debug::debug('WebSocket __construct');
    }

    public function __destruct()
    {
        Debug::debug('WebSocket __destruct');
    }

    public function onData2($connection, $data): void
    {
        call_user_func(array(get_parent_class($this), 'onData'), $data);

    }

    public function onError2($conection, $code, $msg): void
    {
        call_user_func(array(get_parent_class($this), 'onData'), $code, $msg);
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

    public function doClose(callable $fn = null): void
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
