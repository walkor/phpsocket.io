<?php
namespace PHPSocketIO\Engine;
use \PHPSocketIO\Engine\Transports\Polling;
use \PHPSocketIO\Engine\Transports\PollingXHR;
use \PHPSocketIO\Engine\Transports\WebSocket;
use \PHPSocketIO\Event\Emitter;

class Engine extends Emitter
{
    public $pingTimeout = 60;
    public $pingInterval = 25;
    public $upgradeTimeout = 10;
    public $transports = array();
    public $allowUpgrades = array();
    public $allowRequest = array();
    public $clients = array();
    public static $allowTransports = array(
       'polling' => 'polling',
       'websocket' => 'websocket'
    );

    public static $errorMessages = array(
        'Transport unknown',
        'Session ID unknown',
        'Bad handshake method',
        'Bad request'
    );

    const ERROR_UNKNOWN_TRANSPORT = 0;

    const ERROR_UNKNOWN_SID = 1;

    const ERROR_BAD_HANDSHAKE_METHOD = 2;

    const ERROR_BAD_REQUEST = 3;

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
        $this->verify($req, false, array($this, 'dealRequest'));
    }

    public function dealRequest($err, $success, $req)
    {
        if (!$success)
        {
            self::sendErrorMessage($req, $req->res, $err);
            return;
        }

        if(isset($req->_query['sid']))
        {
            $this->clients[$req->_query['sid']]->transport->onRequest($req);
        }
        else
        {
            $this->handshake($req->_query['transport'], $req);
        }
    }

    protected function sendErrorMessage($req, $res, $code)
    {
        $headers = array('Content-Type'=> 'application/json');

        if(isset($req->headers['origin']))
        {
            $headers['Access-Control-Allow-Credentials'] = 'true';
            $headers['Access-Control-Allow-Origin'] = $req->headers['origin'];
        } 
        else 
        {
            $headers['Access-Control-Allow-Origin'] = '*';
        }
        $res->writeHead(400, '', $headers);
        $res->end(json_encode(array(
            'code' => $code,
            'message' => self::$errorMessages[$code]
        )));
    }

    protected function verify($req, $upgrade, $fn)
    {
        if(!isset($req->_query['transport']) || !isset(self::$allowTransports[$req->_query['transport']]))
        {
            return call_user_func($fn, self::ERROR_UNKNOWN_TRANSPORT, false, $req);
        }
        $transport = $req->_query['transport'];
        $sid = isset($req->_query['sid']) ? $req->_query['sid'] : '';
        if($sid)
        {
            if(!isset($this->clients[$sid]))
            {
                return call_user_func($fn, self::ERROR_UNKNOWN_SID, false, $req);
            }
            if(!$upgrade && $this->clients[$sid]->transport->name !== $transport)
            {
                return call_user_func($fn, self::ERROR_BAD_REQUEST, false, $req);
            }
        }
        else
        {
           if('GET' !== $req->method)
           {
              return call_user_func($fn, self::ERROR_BAD_HANDSHAKE_METHOD, false, $req);
           }
        }
        call_user_func($fn, null, true, $req);
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
            $transport = '\\PHPSocketIO\\Engine\\Transports\\PollingJsonp';
        } 
        else 
        {
            $transport = '\\PHPSocketIO\\Engine\\Transports\\PollingXHR';
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
        $socket->once('close', array($this, 'onSocketClose')); 
        $this->emit('connection', $socket);
    }

    public function onSocketClose($id)
    {
        var_dump(isset($this->clients[$id]));
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
            $connection->httpRequest = $connection->httpResponse = $connection->onRequest = $connection->onWebSocketConnect = null;
        };
    }
    
    public function onWebSocketConnect($connection, $req, $res)
    {
        $this->prepare($req);
        $transport = new WebSocket($req);
        $this->clients[$req->_query['sid']]->maybeUpgrade($transport);
    }
}
