<?php
namespace PHPSocketIO\Engine;
use \PHPSocketIO\Engine\Transports\Polling;
use \PHPSocketIO\Engine\Transports\PollingXHR;
use \PHPSocketIO\Engine\Transports\WebSocket;
use \PHPSocketIO\Event\Emitter;
use \PHPSocketIO\Debug;
class Engine extends Emitter
{
    public $pingTimeout = 60;
    public $pingInterval = 25;
    public $upgradeTimeout = 5;
    public $transports = array();
    public $allowUpgrades = array();
    public $allowRequest = array();
    public $clients = array();
    public $origins = '*:*';
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
        Debug::debug('Engine __construct');
    }

    public function __destruct()
    {
        Debug::debug('Engine __destruct');
    }

    public function handleRequest($req, $res)
    {
        $this->prepare($req);
        $req->res = $res;
        $this->verify($req, $res, false, array($this, 'dealRequest'));
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

        $res->writeHead(403, '', $headers);
        $res->end(json_encode(array(
            'code' => $code,
            'message' => isset(self::$errorMessages[$code]) ? self::$errorMessages[$code] : $code
        )));
    }

    protected function verify($req, $res, $upgrade, $fn)
    {
        if(!isset($req->_query['transport']) || !isset(self::$allowTransports[$req->_query['transport']]))
        {
            return call_user_func($fn, self::ERROR_UNKNOWN_TRANSPORT, false, $req, $res);
        }
        $transport = $req->_query['transport'];
        $sid = isset($req->_query['sid']) ? $req->_query['sid'] : '';
        if($sid)
        {
            if(!isset($this->clients[$sid]))
            {
                return call_user_func($fn, self::ERROR_UNKNOWN_SID, false, $req, $res);
            }
            if(!$upgrade && $this->clients[$sid]->transport->name !== $transport)
            {
                return call_user_func($fn, self::ERROR_BAD_REQUEST, false, $req, $res);
            }
        }
        else
        {
            if('GET' !== $req->method)
            {
               return call_user_func($fn, self::ERROR_BAD_HANDSHAKE_METHOD, false, $req, $res);
            }
            return $this->checkRequest($req, $res, $fn);
        }
        call_user_func($fn, null, true, $req, $res);
    }

    public function checkRequest($req, $res, $fn)
    {
        if ($this->origins === "*:*" || empty($this->origins))
        {
            return call_user_func($fn, null, true, $req, $res);
        }
        $origin = null;
        if (isset($req->headers['origin']))
        {
            $origin = $req->headers['origin'];
        }
        else if(isset($req->headers['referer']))
        {
            $origin = $req->headers['referer'];
        }

        // file:// URLs produce a null Origin which can't be authorized via echo-back
        if ('null' === $origin || null === $origin) {
            return call_user_func($fn, null, true, $req, $res);
        }

        if ($origin)
        {
            $parts = parse_url($origin);
            $defaultPort = 'https:' === $parts['scheme'] ? 443 : 80;
            $parts['port'] = isset($parts['port']) ? $parts['port'] : $defaultPort;
            $allowed_origins = explode(' ', $this->origins);
            foreach( $allowed_origins as $allow_origin ){
                $ok =
                    $allow_origin === $parts['scheme'] . '://' . $parts['host'] . ':' . $parts['port'] ||
                    $allow_origin === $parts['scheme'] . '://' . $parts['host'] ||
                    $allow_origin === $parts['scheme'] . '://' . $parts['host'] . ':*' ||
                    $allow_origin === '*:' . $parts['port'];
                return call_user_func($fn, null, $ok, $req, $res);
            }
        }
        call_user_func($fn, null, false, $req, $res);
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
        $id = bin2hex(pack('d', microtime(true)).pack('N', function_exists('random_int') ? random_int(1, 100000000): rand(1, 100000000)));
        if ($transport == 'websocket') {
            $transport = '\\PHPSocketIO\\Engine\\Transports\\WebSocket';
        }
        elseif (isset($req->_query['j']))
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
        
        /* $transport->on('headers', function(&$headers)use($id)
        {
            $headers['Set-Cookie'] = "io=$id";
        }); */

        $transport->onRequest($req);

        $this->clients[$id] = $socket;
        $socket->once('close', array($this, 'onSocketClose'));
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
            if(!empty($connection->httpRequest))
            {
                $connection->httpRequest->destroy();
                $connection->httpRequest = null;
            }
            if(!empty($connection->httpResponse))
            {
                $connection->httpResponse->destroy();
                $connection->httpResponse = null;
            }
            if(!empty($connection->onRequest))
            {
                $connection->onRequest = null;
            }
            if(!empty($connection->onWebSocketConnect))
            {
                $connection->onWebSocketConnect = null;
            }
        };
    }
    
    public function onWebSocketConnect($connection, $req, $res)
    {
        $this->prepare($req);
        $this->verify($req, $res, true, array($this, 'dealWebSocketConnect'));
    }

    public function dealWebSocketConnect($err, $success, $req, $res)
    {
        if (!$success)
        {
            self::sendErrorMessage($req, $res, $err);
            return;
        }


        if(isset($req->_query['sid']))
        {
            if(!isset($this->clients[$req->_query['sid']]))
            {
                self::sendErrorMessage($req, $res, 'upgrade attempt for closed client');
                return;
            }
            $client = $this->clients[$req->_query['sid']];
            if($client->upgrading)
            {
                self::sendErrorMessage($req, $res, 'transport has already been trying to upgrade');
                return;
            }
            if($client->upgraded)
            {
                self::sendErrorMessage($req, $res, 'transport had already been upgraded');
                return;
            }
            $transport = new WebSocket($req);
            $client->maybeUpgrade($transport);
        }
        else
        {
            $this->handshake($req->_query['transport'], $req);
        }
    }
}
