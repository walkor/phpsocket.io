<?php
namespace Engine;
use \Engine\Transport\Polling;
use \Engine\Transport\PollingXHR;
use \Engine\Transport\WebSocket;
use \Event\Emitter;

class Engine extends Emitter
{
    public $pingTimeout = 60;
    public $pingInterval = 25;
    public $upgradeTimeout = 10;
    public $transports = array();
    public $allowUpgrades = array();
    public $allowRequest = array();
    public $clients = array();

    public function __construct($opts = array())
    {
        $ops_map = array(
            'pingTimeout',
             'pingInterval',
             'upgradeTimeout',
             'transports',
             'allowUpgrades',
             'allowRequest'
        );
        foreach($ops_map as $key)
        {
            if(isset($opts[$key]))
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
        if (isset($req->_query['j'])) 
        {
            $transport = '\\Engine\\Transport\\PollingJSONP';
        } 
        else 
        {
            $transport = '\\Engine\\Transport\\PollingXHR';
        }

        $transport = new $transport($req);

        $transport->supportsBinary = !isset($req->_query['b64']);
 
        $socket = new Socket($id, $this, $transport, $req);

        $transport->on('headers', function(&$headers)use($id)
        {
            $headers['Set-Cookie'] = "io=$id";
        });

        $transport->onRequest($req);

        $this->clients[$id] = $socket;
        //$socket->once('close', array($this, 'onSocketClose')); 
        $self = $this;
        $socket->once('close', function()use($id, $self)
        {
           unset($self->clients[$id]);
        });
        $this->emit('connection', $socket);
    }

    public function onSocketClose($id)
    {
        unset($this->clients[$id]);
    }

    public function attach($worker)
    {
        $this->server = $worker;
        $worker->onConnect = array($this, 'onConnect'); 
    }
    
    public function onConnect($connection)
    {
        $connection->onRequest = array($this, 'handleRequest');
        $connection->onWebSocketConnect = array($this, 'onWebSocketConnect');
        // clean
        $connection->onClose = function($connection)
        {
            $connection->httpRequest = $connection->httpResponse = $connection->onRequest = null;
        };
    }
    
    public function onWebSocketConnect($connection, $req, $res)
    {
        $this->prepare($req);
        $transport = new WebSocket($req);
        $this->clients[$req->_query['sid']]->maybeUpgrade($transport);
    }
}
