<?php

namespace PHPSocketIO;

use PHPSocketIO\Event\Emitter;
use PHPSocketIO\Parser\Parser;

class Nsp extends Emitter
{
    // $adapter/$server are intentionally left untyped: duck-typed against
    // whatever SocketIO (or a test double) hands them.
    public $adapter;
    public ?string $name = null;
    public $server = null;

    // Ephemeral per-emit() targeting/flags -- protected for the same reason
    // as Socket::$roomTargets/$flags (no external usage found, and mutating
    // them from outside would corrupt in-flight broadcast bookkeeping).
    protected array $rooms = [];
    protected array $flags = [];

    public array $sockets = [];
    // Read directly by DefaultAdapter::broadcast() and Socket::onconnect()/
    // onclose(), so this stays public.
    public array $connected = [];
    // Incremented by Socket::emit() when assigning ack ids, so this stays
    // public too.
    public int $ids = 0;
    public static array $events = [
        'connect' => 'connect',    // for symmetry with client
        'connection' => 'connection',
        'newListener' => 'newListener'
    ];

    public function getRoomTargets(): array
    {
        return $this->rooms;
    }

    public function getFlags(): array
    {
        return $this->flags;
    }

    public function __construct($server, $name)
    {
        $this->name = $name;
        $this->server = $server;
        $this->initAdapter();
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
        call_user_func_array([$this, 'emit'], $args);
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
