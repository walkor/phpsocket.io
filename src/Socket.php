<?php
namespace PHPSocketIO;
use PHPSocketIO\Event\Emitter;
use PHPSocketIO\Parser\Parser;
class Socket extends Emitter
{
    public $nsp = null;
    public $server = null;
    public $adapter = null;
    public $id = null;
    public $path = '/';
    public $request = null;
    public $client = null;
    public $conn = null;
    public $rooms = array();
    public $_rooms = array();
    public $flags = array();
    public $acks = array();
    public $connected = true;
    public $disconnected = false;
    
    public static $events = array(
        'error'=>'error',
        'connect' => 'connect',
        'disconnect' => 'disconnect',
        'newListener' => 'newListener',
        'removeListener' => 'removeListener'
    );
    
    public static $flagsMap = array(
        'json' => 'json',
        'volatile' => 'volatile',
        'broadcast' => 'broadcast'
    );
    
    public function __construct($nsp, $client)
    {
        $this->nsp = $nsp;
        $this->server = $nsp->server;
        $this->adapter = $this->nsp->adapter;
        $this->id = $client->id;
        $this->request = $client->request;
        $this->client = $client;
        $this->conn = $client->conn;
        $this->handshake = $this->buildHandshake();
        Debug::debug('IO Socket __construct');
    }

public function __destruct()
{
    Debug::debug('IO Socket __destruct');
}
    
    public function buildHandshake()
    {
        //todo check this->request->_query
        $info = parse_url($this->request->url);
        $query = array();
        if(isset($info['query']))
        {
            parse_str($info['query'], $query);
        }
        return array(
            'headers' => $this->request->headers,
            'time'=> date('D M d Y H:i:s') . ' GMT',
            'address'=> $this->conn->remoteAddress,
            'xdomain'=> isset($this->request->headers['origin']),
            'secure' => !empty($this->request->connection->encrypted),
            'issued' => time(),
            'url' => $this->request->url,
            'query' => $query,
       );
    }
   
    public function __get($name)
    {
        if($name === 'broadcast')
        {
            $this->flags['broadcast'] = true;
            return $this;
        }
        return null;
    }
 
    public function emit($ev)
    {
        $args = func_get_args();
        if (isset(self::$events[$ev]))
        {
            call_user_func_array(array($this, 'parent::emit'), $args);
        }
        else
        {
            $packet = array();
            // todo check
            //$packet['type'] = hasBin($args) ? Parser::BINARY_EVENT : Parser::EVENT;
            $packet['type'] = Parser::EVENT;
            $packet['data'] = $args;
            $flags = $this->flags;
            // access last argument to see if it's an ACK callback
            if (is_callable(end($args))) 
            {
                if ($this->_rooms || $flags['broadcast']) 
                {
                    throw new Exception('Callbacks are not supported when broadcasting');
                }
                echo('emitting packet with ack id ' . $this->nsp->ids);
                $this->acks[$this->nsp->ids] = array_pop($args);
                $packet->id = $this->nsp->ids++;
            }
    
            if ($this->_rooms || !empty($flags['broadcast'])) 
            {
                $this->adapter->broadcast($packet, array(
                    'except' => array($this->id => $this->id),
                    'rooms'=> $this->_rooms,
                    'flags' => $flags
                ));
            }
            else
            {
                // dispatch packet
                $this->packet($packet);
            }
    
            // reset flags
            $this->_rooms = array();
            $this->flags = array();
        }
        return $this;
    }
    
    
    /**
     * Targets a room when broadcasting.
     *
     * @param {String} name
     * @return {Socket} self
     * @api public
     */
    
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
    
    /**
     * Sends a `message` event.
     *
     * @return {Socket} self
     * @api public
     */
    
    public function send()
    {
        $args = func_get_args();
        array_unshift($args, 'message');
        call_user_func_array(array($this, 'emit'), $args);
        return $this;
    }
    
    public function write()
    {
        $args = func_get_args();
        array_unshift($args, 'message');
        call_user_func_array(array($this, 'emit'), $args);
        return $this;
    }
    
    /**
     * Writes a packet.
     *
     * @param {Object} packet object
     * @param {Object} options
     * @api private
     */
    
    public function packet($packet, $preEncoded = false)
    {
        $packet['nsp'] = $this->nsp->name;
        //$volatile = !empty(self::$flagsMap['volatile']);
        $volatile = false;
        $this->client->packet($packet, $preEncoded, $volatile);
    }
    
    /**
     * Joins a room.
     *
     * @param {String} room
     * @param {Function} optional, callback
     * @return {Socket} self
     * @api private
     */
    
     public function join($room)
     {
        if(isset($this->rooms[$room])) return $this;
        $this->adapter->add($this->id, $room);
        $this->rooms[$room] = $room;
        return $this;
    }
    
    /**
     * Leaves a room.
     *
     * @param {String} room
     * @param {Function} optional, callback
     * @return {Socket} self
     * @api private
     */
    
    public function leave($room)
    {
        $this->adapter->del($this->id, $room);
        unset($this->rooms[$room]);
        return this;
    }
    
    /**
     * Leave all rooms.
     *
     * @api private
     */
    
    public function leaveAll()
    {
        $this->adapter->delAll($this->id);
        $this->rooms = array();
    }
    
    /**
     * Called by `Namespace` upon succesful
     * middleware execution (ie: authorization).
     *
     * @api private
     */
    
    public function onconnect()
    {
        $this->nsp->connected[$this->id] = $this;
        $this->join($this->id);
        $this->packet(array(
            'type' => Parser::CONNECT)
         );
    }
    
    /**
     * Called with each packet. Called by `Client`.
     *
     * @param {Object} packet
     * @api private
     */
    
    public function onpacket($packet)
    {
        switch ($packet['type']) 
        {
            case Parser::EVENT:
                $this->onevent($packet);
                break;
    
            case Parser::BINARY_EVENT:
                $this->onevent($packet);
                break;
    
            case Parser::ACK:
                $this->onack($packet);
                break;
    
            case Parser::BINARY_ACK:
                $this->onack($packet);
                break;
    
            case Parser::DISCONNECT:
                $this->ondisconnect();
                break;
    
            case Parser::ERROR:
                $this->emit('error', $packet['data']);
        }
    }
    
    /**
     * Called upon event packet.
     *
     * @param {Object} packet object
     * @api private
     */
    
    public function onevent($packet)
    {
        $args = isset($packet['data']) ? $packet['data'] : array();
        if (!empty($packet['id']))
        {
            $args[] = $this->ack($packet['id']);
        }
        call_user_func_array(array($this, 'parent::emit'), $args);
    }
    
    /**
     * Produces an ack callback to emit with an event.
     *
     * @param {Number} packet id
     * @api private
     */
    
    public function ack($id)
    {
        $self = $this;
        $sent = false;
        return function()use(&$sent){
            // prevent double callbacks
            if ($sent) return;
            $args = func_get_args();
            $type = hasBin($args) ? Parser::BINARY_ACK : Parser::ACK;
            $self->packet(array(
                'id' => $id,
                'type' => $type,
                'data' => $args
            ));
        };
    }
    
    /**
     * Called upon ack packet.
     *
     * @api private
     */
    
    public function onack($packet)
    {
        $ack = $this->acks[$packet['id']];
        if (is_callable($ack)) 
        {
            call_user_func($ack, $packet['data']);
            unset($this->acks[$packet['id']]);
        } else {
            echo ('bad ack '. packet.id);
        }
    }
    
    /**
     * Called upon client disconnect packet.
     *
     * @api private
     */
    
    public function ondisconnect()
    {
        echo('got disconnect packet');
        $this->onclose('client namespace disconnect');
    }
    
    /**
     * Handles a client error.
     *
     * @api private
     */
    
    public function onerror($err)
    {
        if ($this->listeners('error')) 
        {
            $this->emit('error', $err);
        } 
        else 
        {
            echo('Missing error handler on `socket`.');
        }
    }
    
    /**
     * Called upon closing. Called by `Client`.
     *
     * @param {String} reason
     * @param {Error} optional error object
     * @api private
     */
    
     public function onclose($reason)
     {
        if (!$this->connected) return $this;
        $this->leaveAll();
        $this->nsp->remove($this);
        $this->client->remove($this);
        $this->connected = false;
        $this->disconnected = true;
        unset($this->nsp->connected[$this->id]);
        $this->emit('disconnect', $reason);
        // ....
        $this->nsp = null;
        $this->server = null;
        $this->adapter = null;
        $this->request = null;
        $this->client = null;
        $this->conn = null;
        $this->removeAllListeners();
    }
    
    /**
     * Produces an `error` packet.
     *
     * @param {Object} error object
     * @api private
     */
    
    public function error($err)
    {
        $this->packet(array(
            'type' => Parser::ERROR, 'data' => $err )
         );
    }
    
    /**
     * Disconnects this client.
     *
     * @param {Boolean} if `true`, closes the underlying connection
     * @return {Socket} self
     * @api public
     */
    
    public function disconnect($close)
    {
        if (!$this->connected) return $this;
        if ($close) 
        {
            $this->client->disconnect();
        } else {
            $this->packet(array(
                'type'=> Parser::DISCONNECT
            ));
            $this->onclose('server namespace disconnect');
        }
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
