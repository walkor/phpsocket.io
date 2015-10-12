<?php
namespace PHPSocketIO;
use PHPSocketIO\Parser\Parser;

class Client
{
    public $server = null;
    public $conn = null;
    public $encoder = null;
    public $decoder = null;
    public $id = null;
    public $request = null;
    public $nsps = array();
    public $connectBuffer = array();
    public function __construct($server, $conn)
    {
        $this->server = $server;
        $this->conn = $conn;
        $this->encoder = new \PHPSocketIO\Parser\Encoder();
        $this->decoder = new \PHPSocketIO\Parser\Decoder();
        $this->id = $conn->id;
        $this->request = $conn->request;
        $this->setup();
        Debug::debug('Client __construct');
    }
    
public function __destruct()
{
    Debug::debug('Client __destruct');
}

/**
 * Sets up event listeners.
 *
 * @api private
 */

    public function setup(){
         $this->decoder->on('decoded', array($this,'ondecoded'));
         $this->conn->on('data', array($this,'ondata'));
         $this->conn->on('error', array($this, 'onerror'));
         $this->conn->on('close' ,array($this, 'onclose'));
    }

/**
 * Connects a client to a namespace.
 *
 * @param {String} namespace name
 * @api private
 */

    public function connect($name){
        if (!isset($this->server->nsps[$name])) 
        {
            $this->packet(array('type'=> Parser::ERROR, 'nsp'=> $name, 'data'=> 'Invalid namespace'));
            return;
        }
        $nsp = $this->server->of($name);
        if ('/' !== $name && !isset($this->nsps['/'])) 
        {
            $this->connectBuffer[$name] = $name;
            return;
        }
        $nsp->add($this, $nsp, array($this, 'nspAdd'));
    }

    public function nspAdd($socket, $nsp)
    {
        $this->sockets[$socket->id] = $socket;
        $this->nsps[$nsp->name] = $socket;
        if ('/' === $nsp->name && $this->connectBuffer)
        {
            foreach($this->connectBuffer as $name)
            {
                $this->connect($name);
            }
            $this->connectBuffer = array();
        }
    }



/**
 * Disconnects from all namespaces and closes transport.
 *
 * @api private
 */

    public function disconnect()
    {
        foreach($this->sockets as $socket)
        {
            $socket->disconnect();
        }
        $this->sockets = array();
        $this->close();
    }

/**
 * Removes a socket. Called by each `Socket`.
 *
 * @api private
 */

    public function remove($socket)
    {
        if(isset($this->sockets[$socket->id]))
        {
            $nsp = $this->sockets[$socket->id]->nsp->name;
            unset($this->sockets[$socket->id]);
            unset($this->nsps[$nsp]);
        } else {
            //echo('ignoring remove for '. $socket->id);
        }
    }

/**
 * Closes the underlying connection.
 *
 * @api private
 */

    public function close()
    {
        if('open' === $this->conn->readyState) 
        {
             echo('forcing transport close');
             $this->conn->close();
             $this->onclose('forced server close');
        }
    }

/**
 * Writes a packet to the transport.
 *
 * @param {Object} packet object
 * @param {Object} options
 * @api private
 */
    public function packet($packet, $preEncoded = false, $volatile = false)
    {
        if('open' === $this->conn->readyState) 
        {
            if (!$preEncoded) 
            {
                // not broadcasting, need to encode
                $encodedPackets = $this->encoder->encode($packet);
                $this->writeToEngine($encodedPackets, $volatile);
            } else { // a broadcast pre-encodes a packet
                 $this->writeToEngine($packet);
            }
        } else {
            // todo check
            // echo('ignoring packet write ' . var_export($packet, true));
        }
    }

    public function  writeToEngine($encodedPackets, $volatile = false) 
    {
        if($volatile)echo new \Exception('volatile');
        if ($volatile && !$this->conn->transport->writable) return;
        // todo check
        if(isset($encodedPackets['nsp']))unset($encodedPackets['nsp']);
        foreach($encodedPackets as $packet) 
        {
             $this->conn->write($packet);
        }
    }


/**
 * Called with incoming transport data.
 *
 * @api private
 */

    public function ondata($data)
    {
        try {
            // todo chek '2["chat message","2"]' . "\0" . '' 
            $this->decoder->add(trim($data));
        } catch(\Exception $e) {
            $this->onerror($e);
        }
    }

/**
 * Called when parser fully decodes a packet.
 *
 * @api private
 */

    public function ondecoded($packet) 
    {
        if(Parser::CONNECT === $packet['type'])
        {
            $this->connect($packet->nsp);
        } else {
            if(isset($this->nsps[$packet['nsp']])) 
            {
                 $this->nsps[$packet['nsp']]->onpacket($packet);
            } else {
                echo('no socket for namespace ' . $packet['nsp']);
            }
        }
    }

/**
 * Handles an error.
 *
 * @param {Objcet} error object
 * @api private
 */

    public function onerror($err)
    {
        foreach($this->sockets as $socket)
        {
            $socket->onerror($err);
        }
        $this->onclose('client error');
    }

/**
 * Called upon transport close.
 *
 * @param {String} reason
 * @api private
 */

    public function onclose($reason)
    {
        // ignore a potential subsequent `close` event
        $this->destroy();

        // `nsps` and `sockets` are cleaned up seamlessly
        foreach($this->sockets as $socket) 
        {
            $socket->onclose($reason);
        }
        $this->sockets = null;
    }

/**
 * Cleans up event listeners.
 *
 * @api private
 */

    public function destroy()
    {
         $this->conn->removeAllListeners();
         $this->decoder->removeAllListeners();
         $this->encoder->removeAllListeners();
         $this->server = $this->conn = $this->encoder = $this->decoder = $this->request = $this->nsps = null;
    }
}
