<?php
namespace PHPSocketIO;
use Workerman\Worker;
use PHPSocketIO\Engine\Engine;
class SocketIO
{
    public $nsps = array();
    protected $_adapter = null;
    public $eio = null;
    public $engine = null;
    protected $_origins = array();
    protected $_path = null;
    
    public function __construct($port = null, $opts = array())
    {
        $adapter = isset($opts['adapter']) ? $opts['adapter'] : '\PHPSocketIO\DefaultAdapter';
        $this->adapter($adapter);
        $origins = isset($opts['origins']) ? $opts['origins'] : '*:*';
        $this->origins($origins);
        $this->sockets = $this->of('/');
        
        if(!class_exists('Protocols\SocketIO'))
        {
            class_alias('PHPSocketIO\Engine\Protocols\SocketIO', 'Protocols\SocketIO');
        }
        if($port)
        {
            $worker = new Worker('SocketIO://0.0.0.0:'.$port);
            $worker->name = 'PHPSocketIO';
            $this->attach($worker);
        }
    }
    
    public function adapter($v = null)
    {
         if (empty($v)) return $this->_adapter;
         $this->_adapter = $v;
         foreach($this->nsps as $nsp)
         {
             $nsp->initAdapter();
         }
         return $this;
    }

    public function origins($v = null)
    {
        if ($v === null) return $this->_origins;
        $this->_origins = $v;
        return $this;
    }

    public function attach($srv, $opts = array())
    {
         $engine = new Engine();
         $this->eio = $engine->attach($srv, $opts);

         // Export http server
         $this->worker = $srv;

         // bind to engine events
         $this->bind($engine);

         return $this;
    }

    public function bind($engine)
    {
        $this->engine = $engine;
        $this->engine->on('connection', array($this, 'onConnection'));
        return $this;
    }
 
    public function of($name, $fn = null)
    {
        if($name[0] !== '/')
        {
            $name = "/$name";
        }
        if(empty($this->nsps[$name]))
        {
            $this->nsps[$name] = new Nsp($this, $name);
        }
        if ($fn)
        {
            $this->nsps[$name]->on('connect', $fn);
        }
        return $this->nsps[$name];
    }
    
    public function onConnection($engine_socket)
    {
        $client = new Client($this, $engine_socket);
        $client->connect('/');
        return $this;
    }

    public function on()
    {
        if(func_get_arg(0) === 'workerStart')
        {
           $this->worker->onWorkerStart = func_get_arg(1);
           return;
        }
        return call_user_func_array(array($this->sockets, 'on'), func_get_args());
    } 

    public function in()
    {
        return call_user_func_array(array($this->sockets, 'in'), func_get_args());
    }

    public function to()
    {
        return call_user_func_array(array($this->sockets, 'to'), func_get_args());
    }

    public function emit()
    {
        return call_user_func_array(array($this->sockets, 'emit'), func_get_args());
    }

    public function send()
    {
        return call_user_func_array(array($this->sockets, 'send'), func_get_args());
    }

    public function write()
    {
        return call_user_func_array(array($this->sockets, 'write'), func_get_args());
    }
}
