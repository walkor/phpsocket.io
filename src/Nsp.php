<?php

namespace PHPSocketIO;

use PHPSocketIO\Event\Emitter;
use PHPSocketIO\Parser\Parser;

class Nsp extends Emitter
{
    public $adapter;
    public $name = null;
    public $server = null;
    public $rooms = [];
    public $flags = [];
    public $sockets = [];
    public $connected = [];
    public $fns = [];
    public $ids = 0;
    public $acks = [];
    public static $events = [
        'connect' => 'connect',    // for symmetry with client
        'connection' => 'connection',
        'newListener' => 'newListener'
    ];

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

    public function to($name): Nsp
    {
        if (! isset($this->rooms[$name])) {
            $this->rooms[$name] = $name;
        }
        return $this;
    }

    public function in($name): Nsp
    {
        return $this->to($name);
    }

    public function add($client, $nsp, $fn)
    {
        $socket_name = $this->server->socket();
        $socket = new $socket_name($this, $client);
        if ('open' === $client->conn->readyState) {
            $this->sockets[$socket->id] = $socket;
            $socket->onconnect();
            if (! empty($fn)) {
                call_user_func($fn, $socket, $nsp);
            }
            $this->emit('connect', $socket);
            $this->emit('connection', $socket);
        } else {
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
     * @param null $ev
     * @return Nsp|void {Namespace} self
     * @api    public
     */
    public function emit($ev = null)
    {
        $args = func_get_args();
        if (isset(self::$events[$ev])) {
            call_user_func_array([get_parent_class(__CLASS__), 'emit'], $args);
        } else {
            // set up packet object

            $parserType = Parser::EVENT; // default
            //if (self::hasBin($args)) { $parserType = Parser::BINARY_EVENT; } // binary

            $packet = ['type' => $parserType, 'data' => $args];

            if (is_callable(end($args))) {
                echo('Callbacks are not supported when broadcasting');
                return;
            }

            $this->adapter->broadcast(
                $packet,
                [
                    'rooms' => $this->rooms,
                    'flags' => $this->flags
                ]
            );

            $this->rooms = [];
            $this->flags = [];
        }
        return $this;
    }

    public function send(): Nsp
    {
        $args = func_get_args();
        array_unshift($args, 'message');
        $this->emit($args);
        return $this;
    }

    public function write()
    {
        $args = func_get_args();
        return call_user_func_array([$this, 'send'], $args);
    }

    public function clients($fn): Nsp
    {
        $this->adapter->clients($this->rooms, $fn);
        return $this;
    }

    /**
     * Sets the compress flag.
     *
     * @param  {Boolean} if `true`, compresses the sending data
     * @return Nsp {Socket} self
     * @api    public
     */
    public function compress($compress): Nsp
    {
        $this->flags['compress'] = $compress;
        return $this;
    }
}
