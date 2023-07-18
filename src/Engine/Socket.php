<?php

namespace PHPSocketIO\Engine;

use \PHPSocketIO\Event\Emitter;
use \Workerman\Timer;
use \PHPSocketIO\Debug;

class Socket extends Emitter
{
    public $id = 0;
    public $server = null;
    public $upgrading = false;
    public $upgraded = false;
    public $readyState = 'opening';
    public $writeBuffer = [];
    public $packetsFn = [];
    public $sentCallbackFn = [];
    public $request = null;
    public $remoteAddress = '';
    public $checkIntervalTimer;
    public $upgradeTimeoutTimer = null;
    public $pingTimeoutTimer = null;

    public function __construct($id, $server, $transport, $req)
    {
        $this->id = $id;
        $this->server = $server;
        $this->request = $req;
        $this->remoteAddress = $req->connection->getRemoteIp() . ':' . $req->connection->getRemotePort();
        $this->setTransport($transport);
        $this->onOpen();
        Debug::debug('Engine/Socket __construct');
    }

    public function __destruct()
    {
        Debug::debug('Engine/Socket __destruct');
    }

    public function maybeUpgrade($transport)
    {
        $this->upgrading = true;
        $this->upgradeTimeoutTimer = Timer::add(
            $this->server->upgradeTimeout,
            [$this, 'upgradeTimeoutCallback'],
            [$transport],
            false
        );
        $this->upgradeTransport = $transport;
        $transport->on('packet', [$this, 'onUpgradePacket']);
        $transport->once('close', [$this, 'onUpgradeTransportClose']);
        $transport->once('error', [$this, 'onUpgradeTransportError']);
        $this->once('close', [$this, 'onUpgradeTransportClose']);
    }

    public function onUpgradePacket($packet)
    {
        if (empty($this->upgradeTransport)) {
            $this->onError('upgradeTransport empty');
            return;
        }
        if ('ping' === $packet['type'] && (isset($packet['data']) && 'probe' === $packet['data'])) {
            $this->upgradeTransport->send([['type' => 'pong', 'data' => 'probe']]);
            //$this->transport->shouldClose = function(){};
            if ($this->checkIntervalTimer) {
                Timer::del($this->checkIntervalTimer);
            }
            $this->checkIntervalTimer = Timer::add(0.5, [$this, 'check']);
        } elseif ('upgrade' === $packet['type'] && $this->readyState !== 'closed') {
            $this->upgradeCleanup();
            $this->upgraded = true;
            $this->clearTransport();
            $this->transport->destroy();
            $this->setTransport($this->upgradeTransport);
            $this->emit('upgrade', $this->upgradeTransport);
            $this->upgradeTransport = null;
            $this->setPingTimeout();
            $this->flush();
            if ($this->readyState === 'closing') {
                $this->transport->close([$this, 'onClose']);
            }
        } else {
            if (! empty($this->upgradeTransport)) {
                $this->upgradeCleanup();
                $this->upgradeTransport->close();
                $this->upgradeTransport = null;
            }
        }
    }


    public function upgradeCleanup()
    {
        $this->upgrading = false;
        Timer::del($this->checkIntervalTimer);
        Timer::del($this->upgradeTimeoutTimer);
        if (! empty($this->upgradeTransport)) {
            $this->upgradeTransport->removeListener('packet', [$this, 'onUpgradePacket']);
            $this->upgradeTransport->removeListener('close', [$this, 'onUpgradeTransportClose']);
            $this->upgradeTransport->removeListener('error', [$this, 'onUpgradeTransportError']);
        }
        $this->removeListener('close', [$this, 'onUpgradeTransportClose']);
    }

    public function onUpgradeTransportClose()
    {
        $this->onUpgradeTransportError('transport closed');
    }

    public function onUpgradeTransportError($err)
    {
        //echo $err;
        $this->upgradeCleanup();
        if ($this->upgradeTransport) {
            $this->upgradeTransport->close();
            $this->upgradeTransport = null;
        }
    }

    public function upgradeTimeoutCallback($transport)
    {
        //echo("client did not complete upgrade - closing transport\n");
        $this->upgradeCleanup();
        if ('open' === $transport->readyState) {
            $transport->close();
        }
    }

    public function setTransport($transport)
    {
        $this->transport = $transport;
        $this->transport->once('error', [$this, 'onError']);
        $this->transport->on('packet', [$this, 'onPacket']);
        $this->transport->on('drain', [$this, 'flush']);
        $this->transport->once('close', [$this, 'onClose']);
        //this function will manage packet events (also message callbacks)
        $this->setupSendCallback();
    }

    public function onOpen()
    {
        $this->readyState = 'open';

        // sends an `open` packet
        $this->transport->sid = $this->id;
        $this->sendPacket(
            'open',
            json_encode(
                [
                    'sid' => $this->id,
                    'upgrades' => $this->getAvailableUpgrades(),
                    'pingInterval' => $this->server->pingInterval * 1000,
                    'pingTimeout' => $this->server->pingTimeout * 1000
                ]
            )
        );

        $this->emit('open');
        $this->setPingTimeout();
    }

    public function onPacket($packet)
    {
        if ('open' === $this->readyState) {
            // export packet event
            $this->emit('packet', $packet);

            // Reset ping timeout on any packet, incoming data is a good sign of
            // other side's liveness
            $this->setPingTimeout();
            switch ($packet['type']) {
                case 'ping':
                    $this->sendPacket('pong');
                    $this->emit('heartbeat');
                    break;

                case 'error':
                    $this->onClose('parse error');
                    break;

                case 'message':
                    $this->emit('data', $packet['data']);
                    $this->emit('message', $packet['data']);
                    break;
            }
        } else {
            echo('packet received with closed socket');
        }
    }

    public function check()
    {
        if ('polling' == $this->transport->name && $this->transport->writable) {
            $this->transport->send([['type' => 'noop']]);
        }
    }

    public function onError($err)
    {
        $this->onClose('transport error', $err);
    }

    public function setPingTimeout()
    {
        if ($this->pingTimeoutTimer) {
            Timer::del($this->pingTimeoutTimer);
        }
        $this->pingTimeoutTimer = Timer::add(
            $this->server->pingInterval + $this->server->pingTimeout,
            [$this, 'pingTimeoutCallback'],
            null,
            false
        );
    }

    public function pingTimeoutCallback()
    {
        $this->transport->close();
        $this->onClose('ping timeout');
    }


    public function clearTransport()
    {
        $this->transport->close();
        Timer::del($this->pingTimeoutTimer);
    }

    public function onClose($reason = '', $description = null)
    {
        if ('closed' !== $this->readyState) {
            Timer::del($this->pingTimeoutTimer);

            if (! empty($this->checkIntervalTimer)) {
                Timer::del($this->checkIntervalTimer);
            }

            $this->checkIntervalTimer = null;

            if (! empty($this->checkIntervalTimer)) {
                Timer::del($this->upgradeTimeoutTimer);
            }

            // clean writeBuffer in next tick, so developers can still
            // grab the writeBuffer on 'close' event
            $this->writeBuffer = [];
            $this->packetsFn = [];
            $this->sentCallbackFn = [];
            $this->clearTransport();
            $this->readyState = 'closed';
            $this->emit('close', $this->id, $reason, $description);
            $this->server = null;
            $this->request = null;
            $this->upgradeTransport = null;
            $this->removeAllListeners();
            if (! empty($this->transport)) {
                $this->transport->removeAllListeners();
                $this->transport = null;
            }
        }
    }

    public function send($data, $options, $callback)
    {
        $this->sendPacket('message', $data, $callback);
        return $this;
    }

    public function write($data, $options = [], $callback = null)
    {
        return $this->send($data, $options, $callback);
    }

    public function sendPacket($type, $data = null, $callback = null)
    {
        if ('closing' !== $this->readyState) {
            $packet = [
                'type' => $type
            ];
            if ($data !== null) {
                $packet['data'] = $data;
            }
            // exports packetCreate event
            $this->emit('packetCreate', $packet);
            $this->writeBuffer[] = $packet;
            //add send callback to object
            if ($callback) {
                $this->packetsFn[] = $callback;
            }
            $this->flush();
        }
    }

    public function flush()
    {
        if ('closed' !== $this->readyState && $this->transport->writable
            && $this->writeBuffer
        ) {
            $this->emit('flush', $this->writeBuffer);
            $this->server->emit('flush', $this, $this->writeBuffer);
            $wbuf = $this->writeBuffer;
            $this->writeBuffer = [];
            if ($this->packetsFn) {
                if (! empty($this->transport->supportsFraming)) {
                    $this->sentCallbackFn[] = $this->packetsFn;
                } else {
                    // @todo check
                    $this->sentCallbackFn[] = $this->packetsFn;
                }
            }
            $this->packetsFn = [];
            $this->transport->send($wbuf);
            $this->emit('drain');
            if ($this->server) {
                $this->server->emit('drain', $this);
            }
        }
    }

    public function getAvailableUpgrades()
    {
        return ['websocket'];
    }

    public function close()
    {
        if ('open' !== $this->readyState) {
            return;
        }

        $this->readyState = 'closing';

        if ($this->writeBuffer) {
            $this->once('drain', [$this, 'closeTransport']);
            return;
        }

        $this->closeTransport();
    }

    public function closeTransport()
    {
        //todo onClose.bind(this, 'forced close'));
        $this->transport->close([$this, 'onClose']);
    }

    public function setupSendCallback()
    {
        $self = $this;
        //the message was sent successfully, execute the callback
        $this->transport->on('drain', [$this, 'onDrainCallback']);
    }

    public function onDrainCallback()
    {
        if ($this->sentCallbackFn) {
            $seqFn = array_shift($this->sentCallbackFn);
            if (is_callable($seqFn)) {
                echo('executing send callback');
                call_user_func($seqFn, $this->transport);
            } elseif (is_array($seqFn)) {
                echo('executing batch send callback');
                foreach ($seqFn as $fn) {
                    call_user_func($fn, $this->transport);
                }
            }
        }
    }
}
