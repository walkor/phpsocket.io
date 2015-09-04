<?php
namespace Engine\Transport;
use \Engine\Transport;
use \Engine\Parser;
class WebSocket extends Transport
{
    public $writable = true;
    public $supportsFraming = true;
    public function __construct($req)
    {
        $this->socket = $req->connection;
        $this->socket->onMessage = array($this. 'onData');
        $this->socket->onClose = array($this, 'onClose');
        $this->socket->onError = array($this, 'onError');
    }
    
    public function onData($connection, $data) 
    {
        call_user_func(array($this, 'parent::onData'), $data);
    }
    
    public function onError($conection, $code, $msg)
    {
        call_user_func(array($this, 'parent::onClose'), $code, $msg);
    }
    
    public function send($packets)
    {
        foreach($packets as $packet)
        {
            $self = $this;
            Parser::encodePacket($packet, $this->supportsBinary, function($data)use($self) 
            {
                $self->socket->send(data);
                $self->emit('drain');
            });
        }
    }
    
    public function doClose($fn = null) 
    {
        $this->socket->close();
        if(!empty($fn))
        {
            call_user_func($fn);
        }
    }
}
