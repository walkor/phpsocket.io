<?php
use Workerman\Worker;

class SocketIO extends Worker
{
    protected $_nsp = array();
    protected $_adpter = null;
    protected $_origins = array();
    
    public function __construct($address)
    {
        parent::__construct($address);
        $this->_adpter = new DefaultAdpter();
        $this->onConnect = array($this, 'onConnection');
    }
    
    public function of($name, $fn = null)
    {
        if($name[0] !== '/')
        {
            $name = "/$name";
        }
        if(empty($this->_nsp[$name]))
        {
            $this->_nsp[$name] = new Nsp($this, $name);
        }
        if ($fn)
        {
            $this->_nsps[$name]->on('connect', $fn);
        }
        return $this->_nsp[$name];
    }
    
    public function onConnection($engine_socket)
    {
        $client = new Client($this, $engine_socket);
        $client->connect('/');
        return $this;
    }

    
}
