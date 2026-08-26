<?php

namespace PHPSocketIO;

use Closure;
use Exception;
use PHPSocketIO\Event\Emitter;
use PHPSocketIO\Parser\Parser;

class Socket extends Emitter
{
    // $nsp/$server/$adapter/$request/$client/$conn are intentionally left
    // untyped: they're duck-typed against whatever the surrounding SocketIO
    // wiring (or a test double) hands them, not strictly the concrete
    // Nsp/SocketIO/Http\Request/Client classes.
    /** @var object|null */
    public $nsp = null;
    /** @var object|null */
    public $server = null;
    /** @var object|null */
    public $adapter = null;
    public ?string $id = null;
    public string $path = '/';
    /** @var object|null */
    public $request = null;
    /** @var object|null */
    public $client = null;
    /** @var object|null */
    public $conn = null;
    /** @var array<string, string> */
    public array $rooms = [];

    // Ephemeral per-emit() state. No evidence of external use (checked real
    // consumers on GitHub while raising the floor for v3.0.0) and mutating
    // these from outside would corrupt in-flight broadcast bookkeeping, so
    // they're now protected -- read them via getRoomTargets()/getFlags()/
    // getAcks() if you need to inspect them.
    /** @var array<string, string> */
    protected array $roomTargets = [];
    /** @var array<string, mixed> */
    protected array $flags = [];
    /** @var array<int, callable> */
    protected array $acks = [];

    public bool $connected = true;
    public bool $disconnected = false;
    /** @var array<string, mixed> */
    public array $handshake = [];
    // Left untyped: real-world consumers assign a mix of int/string here
    // depending on their own user id scheme.
    /** @var int|string|null */
    public $userId = null;
    public bool $isGuest = false;
    public ?bool $addedUser = null;
    public ?string $username = null;

    /** @var array<string, string> */
    public static array $events = [
        'error' => 'error',
        'connect' => 'connect',
        'disconnect' => 'disconnect',
        'newListener' => 'newListener',
        'removeListener' => 'removeListener'
    ];

    /** @var array<string, string> */
    public static array $flagsMap = [
        'json' => 'json',
        'volatile' => 'volatile',
        'broadcast' => 'broadcast'
    ];

    /**
     * @return array<string, string>
     */
    public function getRoomTargets(): array
    {
        return $this->roomTargets;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFlags(): array
    {
        return $this->flags;
    }

    /**
     * @return array<int, callable>
     */
    public function getAcks(): array
    {
        return $this->acks;
    }

    /**
     * @param object $nsp
     * @param object $client
     */
    public function __construct($nsp, $client)
    {
        $this->nsp = $nsp;
        $this->server = $nsp->server;
        $this->adapter = $this->nsp->adapter;
        $this->id = ($nsp->name !== '/') ? $nsp->name . '#' . $client->id : $client->id;
        $this->request = $client->request;
        $this->client = $client;
        $this->conn = $client->conn;
        $this->handshake = $this->buildHandshake();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildHandshake(): array
    {
        //todo check this->request->_query
        $info = ! empty($this->request->url) ? parse_url($this->request->url) : [];
        $query = [];
        if (isset($info['query'])) {
            parse_str($info['query'], $query);
        }
        return [
            'headers' => $this->request->headers ?? [],
            'time' => date('D M d Y H:i:s') . ' GMT',
            'address' => $this->conn->remoteAddress,
            'xdomain' => isset($this->request->headers['origin']),
            'secure' => ! empty($this->request->connection->encrypted),
            'issued' => time(),
            'url' => $this->request->url ?? '',
            'query' => $query,
        ];
    }

    /**
     * @return static|null
     */
    public function __get(string $name)
    {
        if ($name === 'broadcast') {
            $this->flags['broadcast'] = true;
            return $this;
        }
        return null;
    }

    /**
     * @param mixed $ev
     * @return Socket
     * @throws Exception
     */
    public function emit($ev = null)
    {
        $args = func_get_args();
        if (isset(self::$events[$ev])) {
            parent::emit(...$args);
        } else {
            $packet = [];
            $packet['type'] = Parser::EVENT;
            $flags = $this->flags;
            // access last argument to see if it's an ACK callback
            if (is_callable(end($args))) {
                if ($this->roomTargets || isset($flags['broadcast'])) {
                    throw new Exception('Callbacks are not supported when broadcasting');
                }
                $this->acks[$this->nsp->ids] = array_pop($args);
                $packet['id'] = $this->nsp->ids++;
            }
            $packet['data'] = $args;

            if ($this->roomTargets || ! empty($flags['broadcast'])) {
                $this->adapter->broadcast(
                    $packet,
                    [
                        'except' => [$this->id => $this->id],
                        'rooms' => $this->roomTargets,
                        'flags' => $flags
                    ]
                );
            } else {
                // dispatch packet
                $this->packet($packet);
            }

            // reset flags
            $this->roomTargets = [];
            $this->flags = [];
        }
        return $this;
    }


    /**
     * Targets a room when broadcasting.
     *
     * @return Socket {Socket} self
     */
    public function to(string $name): Socket
    {
        if (! isset($this->roomTargets[$name])) {
            $this->roomTargets[$name] = $name;
        }
        return $this;
    }

    public function in(string $name): Socket
    {
        return $this->to($name);
    }

    /**
     * Sends a `message` event.
     *
     * @return Socket {Socket} self
     */
    public function send(): Socket
    {
        $args = func_get_args();
        array_unshift($args, 'message');
        call_user_func_array([$this, 'emit'], $args);
        return $this;
    }

    public function write(): Socket
    {
        $args = func_get_args();
        array_unshift($args, 'message');
        call_user_func_array([$this, 'emit'], $args);
        return $this;
    }

    /**
     * Writes a packet.
     *
     * @param array<string, mixed> $packet
     * @param mixed $preEncoded
     */
    public function packet(array $packet, $preEncoded = false): void
    {
        if (! $this->nsp || ! $this->client) {
            return;
        }
        $packet['nsp'] = $this->nsp->name;
        $this->client->packet($packet, $preEncoded, false);
    }

    /**
     * Joins a room.
     *
     * @return Socket {Socket} self
     */
    public function join(string $room): Socket
    {
        if (! $this->connected) {
            return $this;
        }
        if (isset($this->rooms[$room])) {
            return $this;
        }
        $this->adapter->add($this->id, $room);
        $this->rooms[$room] = $room;
        return $this;
    }

    /**
     * Leaves a room.
     *
     * @return Socket {Socket} self
     */
    public function leave(string $room): Socket
    {
        $this->adapter->del($this->id, $room);
        unset($this->rooms[$room]);
        return $this;
    }

    /**
     * Leave all rooms.
     */

    public function leaveAll(): void
    {
        $this->adapter->delAll($this->id);
        $this->rooms = [];
    }

    /**
     * Called by `Namespace` upon succesful
     * middleware execution (ie: authorization).
     */
    public function onconnect(): void
    {
        $this->nsp->connected[$this->id] = $this;
        $this->join($this->id);
        $this->packet(
            [
                'type' => Parser::CONNECT
            ]
        );
    }

    /**
     * Called with each packet. Called by `Client`.
     *
     * @param array<string, mixed> $packet
     * @throws Exception
     */
    public function onpacket(array $packet): void
    {
        switch ($packet['type']) {
            case Parser::BINARY_EVENT:
            case Parser::EVENT:
                $this->onevent($packet);
                break;
            case Parser::BINARY_ACK:
            case Parser::ACK:
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
     * @param array<string, mixed> $packet
     */
    public function onevent(array $packet): void
    {
        $args = $packet['data'] ?? [];
        if (! empty($packet['id']) || (isset($packet['id']) && $packet['id'] === 0)) {
            $args[] = $this->ack($packet['id']);
        }
        parent::emit(...$args);
    }

    /**
     * Produces an ack callback to emit with an event.
     */
    public function ack(int $id): Closure
    {
        $sent = false;
        return function () use (&$sent, $id) {
            $self = $this;
            // prevent double callbacks
            if ($sent) {
                return;
            }
            $sent = true;
            $args = func_get_args();
            $type = $this->hasBin($args) ? Parser::BINARY_ACK : Parser::ACK;
            $self->packet(
                [
                    'id' => $id,
                    'type' => $type,
                    'data' => $args
                ]
            );
        };
    }

    /**
     * Called upon ack packet.
     *
     * @param array<string, mixed> $packet
     */
    public function onack(array $packet): void
    {
        $ack = $this->acks[$packet['id']];
        // Not always true: an unknown/already-fired id yields null, not a callable.
        // @phpstan-ignore function.alreadyNarrowedType
        if (is_callable($ack)) {
            call_user_func($ack, $packet['data']);
            unset($this->acks[$packet['id']]);
        }
    }

    /**
     * Called upon client disconnect packet.
     *
     * @throws Exception
     */
    public function ondisconnect(): void
    {
        $this->onclose('client namespace disconnect');
    }

    /**
     * Handles a client error.
     *
     * @param mixed $err
     * @throws Exception
     */
    public function onerror($err): void
    {
        if ($this->listeners('error')) {
            $this->emit('error', $err);
        }
    }

    /**
     * Called upon closing. Called by `Client`.
     *
     * @return Socket|null
     * @throws Exception
     */
    public function onclose(string $reason)
    {
        if (! $this->connected) {
            return $this;
        }
        $this->emit('disconnect', $reason);
        $this->leaveAll();
        $this->nsp->remove($this);
        $this->client->remove($this);
        $this->connected = false;
        $this->disconnected = true;
        unset($this->nsp->connected[$this->id]);
        // ....
        $this->nsp = null;
        $this->server = null;
        $this->adapter = null;
        $this->request = null;
        $this->client = null;
        $this->conn = null;
        $this->removeAllListeners();
        return null;
    }

    /**
     * Produces an `error` packet.
     *
     * @param mixed $err
     */
    public function error($err): void
    {
        $this->packet(
            [
                'type' => Parser::ERROR, 'data' => $err
            ]
        );
    }

    /**
     * Disconnects this client.
     *
     * @param bool $close
     * @return Socket {Socket} self
     * @throws Exception
     */
    public function disconnect(bool $close = false): Socket
    {
        if (! $this->connected) {
            return $this;
        }
        if ($close) {
            $this->client->disconnect();
        } else {
            $this->packet(
                [
                    'type' => Parser::DISCONNECT
                ]
            );
            $this->onclose('server namespace disconnect');
        }
        return $this;
    }

    /**
     * Sets the compress flag.
     *
     * @return Socket {Socket} self
     */
    public function compress(bool $compress): Socket
    {
        $this->flags['compress'] = $compress;
        return $this;
    }

    /**
     * @param array<int, mixed> $args
     */
    protected function hasBin(array $args): bool
    {
        $hasBin = false;

        array_walk_recursive(
            $args,
            function ($item, $key) use (&$hasBin) {
                if (! ctype_print($item)) {
                    $hasBin = true;
                }
            }
        );

        return $hasBin;
    }
}
