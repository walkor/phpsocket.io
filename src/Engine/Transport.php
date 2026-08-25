<?php

namespace PHPSocketIO\Engine;

use PHPSocketIO\Event\Emitter;

class Transport extends Emitter
{
    public string $readyState = 'opening';
    public ?object $req = null;
    public ?object $res = null;
    public $shouldClose = null;

    public function noop()
    {
    }

    public function onRequest($req)
    {
        $this->req = $req;
    }

    public function close(?callable $fn = null): void
    {
        $this->readyState = 'closing';
        $fn = $fn ?: [$this, 'noop'];
        $this->doClose($fn);
    }

    public function onError(string $msg, string $desc = '')
    {
        if ($this->listeners('error')) {
            $this->emit('error', "TransportError: {$msg}" . ($desc ? " - {$desc}" : ''));
        }
    }

    public function onPacket($packet): void
    {
        $this->emit('packet', $packet);
    }

    public function onData($data)
    {
        $this->onPacket(Parser::decodePacket($data));
    }

    public function onClose()
    {
        $this->req = $this->res = null;
        $this->readyState = 'closed';
        $this->emit('close');
        $this->removeAllListeners();
    }

    public function destroy(): void
    {
        $this->req = null;
        $this->res = null;
        $this->readyState = 'closed';
        $this->removeAllListeners();
        $this->shouldClose = null;
    }
}
