<?php
use \Event\Emitter;
use \Workerman\Lib\Timer;
class Socket extends Emitter
{
    public $id = 0;
    public $server = null;
    public $upgraded = false;
    public $readyState = 'opening';
    public $writeBuffer = array();
    public $packetsFn = array();
    public $sentCallbackFn = array();
    public $request = null;
    public $remoteAddress = '';
    public $checkIntervalTimer = null;
    public $upgradeTimeoutTimer = null;
    public $pingTimeoutTimer = null;

    public function __construct($id, $server, $transport, $req)
    {
        $this->id = $id;
        $this->server = $server;
        $this->request = $req;
        $this->remoteAddress = $req->connection->getRemoteIp().':'.$req->connection->getRemotePort();
        $this->setTransport($transport);
        $this->onOpen();
    }
    
    public function setTransport($transport)
    {
        $this->transport = $transport;
        $this->transport->once('error', array($this, 'onError'));
        $this->transport->on('packet', array($this, 'onPacket'));
        $this->transport->on('drain', array($this, 'flush'));
        $this->transport->once('close', array($this, 'onClose'));
        //this function will manage packet events (also message callbacks)
        //$this->setupSendCallback();
    }
    
    public function onOpen()
    {
        $this->readyState = 'open';
        
        // sends an `open` packet
        $this->transport->sid = $this->id;
        $this->sendPacket('open', json_encode(array(
            'sid'=> $this->id
            , 'upgrades' => $this->getAvailableUpgrades()
            , 'pingInterval'=> $this->server->pingInterval
            , 'pingTimeout'=> $this->server->pingTimeout
        )));
        
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
        
            switch (packet.type) {
        
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
        } 
        else 
        {
            echo('packet received with closed socket');
        }
    } 
    
    public function onError($err) 
    {
      $this->onClose('transport error', $err);
    }
    
    public function setPingTimeout()
    {
        $self = $this;
        Timer::del($this->pingTimeoutTimer);
        $this->pingTimeoutTimer = Timer::add($this->server->pingInterval + $this->server->pingTimeout ,
         function () {
            $self->onClose('ping timeout');
        }, null, false);
    }
    
    public function clearTransport()
    {
        Timer::del($this->pingTimeoutTimer);
    }
    
    public function onClose($reason, $description)
    {
        if ('closed' != $this->readyState) {
            Timer::del($this->pingTimeoutTimer);
            Timer::del($this->checkIntervalTimer);
            $this->checkIntervalTimer = null;
            Timer::del($this->upgradeTimeoutTimer);
            // clean writeBuffer in next tick, so developers can still
            // grab the writeBuffer on 'close' event
            $this->writeBuffer = array();
            $this->packetsFn = array();
            $this->sentCallbackFn = array();
            $this->clearTransport();
            $this->readyState = 'closed';
            $this->emit('close', $reason, $description);
        }
    }
    
    public function send($data, $options, $callback)
    {
        $this->sendPacket('message', $data, $options, $callback);
        return $this;
    }
    
    public function write($data, $options, $callback)
    {
        return $this->send($data, $options, $callback);
    }
    
    public function sendPacket($type, $data = '', $options = array(), $callback = null)
    {
        if(is_callable($options))
         {
            $callback = $options;
            $options = array();
        }
        if('closing' !== $this->readyState) 
        {
            $packet = array(
                'type'=> $type,
                'options'=> $options
            );
            if($data) 
            {
                $packet['data'] = $data;
            }
            // exports packetCreate event
            $this->emit('packetCreate', $packet);
            $this->writeBuffer[] = $packet;
            //add send callback to object
            $this->packetsFn[] = $callback;
            $this->flush();
        }
    }
    
    public function flush() 
    {
        if ('closed' != $this->readyState && $this->transport->writable
        && $this->writeBuffer) 
        {
            $this->emit('flush', $this->writeBuffer);
            $this->server->emit('flush', $this, $this->writeBuffer);
            $wbuf = $this->writeBuffer;
            $this->writeBuffer = array();
            if(!empty($this->transport->supportsFraming)) 
            {
                $this->sentCallbackFn[] = $this->packetsFn;
            } 
            else 
            {
               // @todo check
               $this->sentCallbackFn[]=$this->packetsFn;
            }
            $this->packetsFn = array();
            $this->transport->send($wbuf);
            $this->emit('drain');
            $this->server->emit('drain', $this);
        }
    }

    public function getAvailableUpgrades()
    {
        return array('websocket');
    }

    public function close()
    {
        if ('open' != $this->readyState)
        {
            return;
        }
   
        $this->readyState = 'closing';

        if ($this->writeBuffer) {
            $this->once('drain', array($this, 'closeTransport'));
            return;
        }

        $this->closeTransport();
    }

    public function closeTransport()
    {
        //todo onClose.bind(this, 'forced close'));
        $this->transport->close(array($this, 'onClose'));
    }
}
