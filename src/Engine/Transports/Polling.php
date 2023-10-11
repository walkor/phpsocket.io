<?php

namespace PHPSocketIO\Engine\Transports;

use PHPSocketIO\Engine\Transport;
use PHPSocketIO\Engine\Parser;

class Polling extends Transport
{
    public $name = 'polling';
    public $chunks = '';
    public $shouldClose = null;
    public $writable = false;
    public $supportsBinary = null;
    public $dataRes = null;
    public $dataReq = null;

    public function onRequest($req)
    {
        $res = $req->res;

        if ('GET' === $req->method) {
            $this->onPollRequest($req, $res);
        } elseif ('POST' === $req->method) {
            $this->onDataRequest($req, $res);
        } else {
            $res->writeHead(500);
            $res->end();
        }
    }

    public function onPollRequest(object $req, object $res): void
    {
        if ($this->req) {
            $this->onError('overlap from client');
            $res->writeHead(500);
            return;
        }

        $this->req = $req;
        $this->res = $res;

        $req->onClose = [$this, 'pollRequestOnClose'];
        $req->cleanup = [$this, 'pollRequestClean'];

        $this->writable = true;
        $this->emit('drain');

        if ($this->writable && $this->shouldClose) {
            echo('triggering empty send to append close packet');
            $this->send([['type' => 'noop']]);
        }
    }

    public function pollRequestOnClose(): void
    {
        $this->onError('poll connection closed prematurely');
        $this->pollRequestClean();
    }

    public function pollRequestClean(): void
    {
        if (isset($this->req)) {
            $this->req = null;
            $this->res = null;
        }
    }

    public function onDataRequest($req, $res): void
    {
        if (isset($this->dataReq)) {
            $this->onError('data request overlap from client');
            $res->writeHead(500);
            return;
        }

        $this->dataReq = $req;
        $this->dataRes = $res;
        $req->onClose = [$this, 'dataRequestOnClose'];
        $req->onData = [$this, 'dataRequestOnData'];
        $req->onEnd = [$this, 'dataRequestOnEnd'];
    }

    public function dataRequestCleanup(): void
    {
        $this->chunks = '';
        $this->dataReq = null;
        $this->dataRes = null;
    }

    public function dataRequestOnClose(): void
    {
        $this->dataRequestCleanup();
        $this->onError('data request connection closed prematurely');
    }

    public function dataRequestOnData($req, $data): void
    {
        $this->chunks .= $data;
    }

    public function dataRequestOnEnd(): void
    {
        $this->onData($this->chunks);

        $headers = [
            'Content-Type' => 'text/html',
            'Content-Length' => 2,
            'X-XSS-Protection' => '0',
        ];

        $this->dataRes->writeHead(200, '', $this->headers($this->dataReq, $headers));
        $this->dataRes->end('ok');
        $this->dataRequestCleanup();
    }

    public function onData($data)
    {
        $packets = Parser::decodePayload($data);
        if (isset($packets['type'])) {
            if ('close' === $packets['type']) {
                $this->onClose();
                return false;
            } else {
                $packets = [$packets];
            }
        }

        foreach ($packets as $packet) {
            $this->onPacket($packet);
        }
    }

    public function onClose()
    {
        if ($this->writable) {
            $this->send([['type' => 'noop']]);
        }
        parent::onClose();
    }

    public function send($packets): void
    {
        $this->writable = false;
        if ($this->shouldClose) {
            echo('appending close packet to payload');
            $packets[] = ['type' => 'close'];
            call_user_func($this->shouldClose);
            $this->shouldClose = null;
        }
        $data = Parser::encodePayload($packets, $this->supportsBinary);
        $this->write($data);
    }

    public function write($data): void
    {
        $this->doWrite($data);
        if (! empty($this->req->cleanup)) {
            call_user_func($this->req->cleanup);
        }
    }

    public function doClose(callable $fn): void
    {
        if (! empty($this->dataReq)) {
            $this->dataReq->destroy();
        }

        if ($this->writable) {
            $this->send([['type' => 'close']]);
            call_user_func($fn);
        } else {
            $this->shouldClose = $fn;
        }
    }
}
