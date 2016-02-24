<?php
namespace PHPSocketIO;
use PHPSocketIO\Event\Emitter;
use PHPSocketIO\Parser\Parser;
class Nsp extends Emitter
{
    public $name = null;
    public $server = null;
    public $rooms = array();
    public $flags = array();
    public $sockets = array();
    public $connected = array();
    public $fns = array();
    public $ids = 0;
    public $acks = array();
    public static $events = array(
        'connect' => 'connect',    // for symmetry with client
        'connection' => 'connection',
        'newListener' => 'newListener'
    );
    
    //public static $flags = array('json','volatile');

    public function __construct($server, $name)
    {
         $this->name = $name;
         $this->server = $server;
         $this->initAdapter();
         Debug::debug('Nsp __construct');
    }

public function __destruct()
{
    Debug::debug('Nsp __destruct');
}

    public function initAdapter()
    {
        $adapter_name = $this->server->adapter();
        $this->adapter = new $adapter_name($this);
    }

    public function to($name)
    {
        if(!isset($this->rooms[$name]))
        {
            $this->rooms[$name] = $name;
        }
        return $this;
    }

    public function in($name)
    {
        return $this->to($name);
    }


    public function add($client, $nsp, $fn)
    {
        $socket = new Socket($this, $client);
        if('open' === $client->conn->readyState)
        {
            $this->sockets[$socket->id]=$socket;
            $socket->onconnect();
            if(!empty($fn)) call_user_func($fn, $socket, $nsp);
            $this->emit('connect', $socket);
            $this->emit('connection', $socket);
        }
        else
        {
            echo('next called after client was closed - ignoring socket');
        }
    }

    
    /**
 * Removes a client. Called by each `Socket`.
 *
 * @api private
 */

    public function remove($socket)
    {
        // todo $socket->id
        unset($this->sockets[$socket->id]);
    }


/**
 * Emits to all clients.
 *
 * @return {Namespace} self
 * @api public
 */

    public function emit($ev = null)
    {
        $args = func_get_args();
        if (isset(self::$events[$ev]))
        {
            call_user_func_array(array($this, 'parent::emit'), $args);
        }
        else 
        {
            // set up packet object
            
            $parserType = Parser::EVENT; // default
            //if (self::hasBin($args)) { $parserType = Parser::BINARY_EVENT; } // binary

            $packet = array('type'=> $parserType, 'data'=> $args );

            if (is_callable(end($args))) 
            {
                echo('Callbacks are not supported when broadcasting');
                return;
             }

             $this->adapter->broadcast($packet, array(
                 'rooms'=> $this->rooms,
                 'flags'=> $this->flags
             ));

            $this->rooms = array();
            $this->flags = array();;
        }
        return $this;
    }
    
    public function send()
    {
        $args = func_get_args();
        array_unshift($args, 'message');
        $this->emit($args);
        return $this;
    }

    public function write()
    {
        $args = func_get_args();
        return call_user_func_array(array($this, 'send'), $args);
    }
    
    public function clients($fn)
    {
        $this->adapter->clients($this->rooms, $fn);
        return $this;
    }
    
    /**
     * Sets the compress flag.
     *
     * @param {Boolean} if `true`, compresses the sending data
     * @return {Socket} self
     * @api public
     */
    
     public function compress($compress)
     {
        $this->flags['compress'] = $compress;
        return $this;
    }
}
