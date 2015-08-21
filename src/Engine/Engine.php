<?php
use \Event\Emitter;

class Engine extends Emitter
{
    public $pingTimeout = 60;
    public $pingInterval = 25;
    public $upgradeTimeout = 10;
    public $transports = array();
    public $allowUpgrades = array();
    public $allowRequest = array();
    protected $_clients = array();

    public function __construct($ops = array())
    {
        $ops_map = array('pingTimeout',
                         'pingInterval',
                         'upgradeTimeout',
                         'transports',
                         'allowUpgrades',
                         'allowRequest');
        foreach($ops_map as $key)
        {
            if(isset($ops[$key]))
            {
                $this->$key = $opts[$key];
            }
        }
        
    }

    public function handleRequest($req, $res)
    {
        $this->prepare($req);
        $req->res = $res;
        if(isset($req->_query['sid']))
        {
            $this->clients[$req->_query['sid']]->transport->onRequest($req);
        }
        else
        {
            $this->handshake($req->_query['transport'], $req);
        }
    }

    protected function prepare($req)
    {
        if(!isset($req->_query))
        {
            $info = parse_url($req->url);
            if(isset($info['query']))
            {
                parse_str($info['query'], $req->_query);
            }
        }
    }

    public function handshake($transport, $req)
    {
        $id = rand(1, 100000000);
        $transport = '\\Transport\\'.$transport;
        $transport = new $transport($req);

        $transport->supportsBinary = !isset($req->_query['b64']);
 
        $socket = new Socket($id, $this, $transport, $req);

        $transport->on('headers', function($transport)
        {
            $transport->req->res->headers['Set-Cookie'] = "io=$id";
        });

        $transport->onRequest($req);

        $this->clients[$id] = $socket;
        $socket.once('close', function() use ($id){
            unset($this->clients[$id]);
        });

        $this->emit('connection', $socket);
    }

    public function attach($worker)
    {
        $this->server = $worker;
        $worker->onConnect = array($this, 'onConnect'); 
    }
    
    public function onConnect($connection)
    {
        $connection->onRequest = array($this, 'handleRequest');
        // clean
        $connection->onClose = function($connection)
        {
            $connection->httpRequest = $connection->httpResponse = $connection->onRequest = null;
        };
    }
}
