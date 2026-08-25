<?php

namespace PHPSocketIO;

use Exception;
use PHPSocketIO\Event\Emitter;
use PHPSocketIO\Parser\Decoder;
use PHPSocketIO\Parser\Encoder;
use PHPSocketIO\Parser\Parser;

class Client
{
    public ?SocketIO $server = null;
    // Real usage: Engine\Socket. Tests: a lightweight fake. Both extend
    // Emitter, so that's as specific as this can safely be typed.
    public ?Emitter $conn = null;
    public ?Encoder $encoder = null;
    public ?Decoder $decoder = null;
    public ?string $id = null;
    public ?object $request = null;
    public array $nsps = [];
    public array $connectBuffer = [];
    public array $sockets = [];

    public function __construct(SocketIO $server, Emitter $conn)
    {
        $this->server = $server;
        $this->conn = $conn;
        $this->encoder = new Encoder();
        $this->decoder = new Decoder();
        $this->id = $conn->id;
        $this->request = $conn->request;
        $this->setup();
    }

    /**
     * Sets up event listeners.
     *
     * @api private
     */

    public function setup(): void
    {
        $this->decoder->on('decoded', [$this, 'ondecoded']);
        $this->conn->on('data', [$this, 'ondata']);
        $this->conn->on('error', [$this, 'onerror']);
        $this->conn->on('close', [$this, 'onclose']);
    }

    /**
     * Connects a client to a namespace.
     *
     * @param {String} namespace name
     * @api   private
     */

    public function connect(string $name): void
    {
        if (! isset($this->server->nsps[$name])) {
            $this->packet(['type' => Parser::ERROR, 'nsp' => $name, 'data' => 'Invalid namespace']);
            return;
        }
        $nsp = $this->server->of($name);
        if ('/' !== $name && ! isset($this->nsps['/'])) {
            $this->connectBuffer[$name] = $name;
            return;
        }
        $nsp->add($this, $nsp, [$this, 'nspAdd']);
    }

    public function nspAdd($socket, $nsp): void
    {
        $this->sockets[$socket->id] = $socket;
        $this->nsps[$nsp->name] = $socket;
        if ('/' === $nsp->name && $this->connectBuffer) {
            foreach ($this->connectBuffer as $name) {
                $this->connect($name);
            }
            $this->connectBuffer = [];
        }
    }

    /**
     * Disconnects from all namespaces and closes transport.
     *
     * @api private
     */
    public function disconnect(): void
    {
        foreach ($this->sockets as $socket) {
            $socket->disconnect();
        }
        $this->sockets = [];
        $this->close();
    }

    /**
     * Removes a socket. Called by each `Socket`.
     *
     * @api private
     */
    public function remove($socket): void
    {
        if (isset($this->sockets[$socket->id])) {
            $nsp = $this->sockets[$socket->id]->nsp->name;
            unset($this->sockets[$socket->id]);
            unset($this->nsps[$nsp]);
        }
    }

    /**
     * Closes the underlying connection.
     *
     * @api private
     */
    public function close(): void
    {
        if (empty($this->conn)) {
            return;
        }
        if ('open' === $this->conn->readyState) {
            $this->conn->close();
            $this->onclose($this->id, 'forced server close');
        }
    }

    /**
     * Writes a packet to the transport.
     *
     * @param {Object} packet object
     * @param {Object} options
     * @api   private
     */
    public function packet(array $packet, $preEncoded = false, ?bool $volatile = false): void
    {
        if (! empty($this->conn) && 'open' === $this->conn->readyState) {
            if (! $preEncoded) {
                // not broadcasting, need to encode
                $encodedPackets = $this->encoder->encode($packet);
                $this->writeToEngine($encodedPackets, $volatile);
            } else { // a broadcast pre-encodes a packet
                $this->writeToEngine($packet);
            }
        }
    }

    public function writeToEngine(array $encodedPackets, ?bool $volatile = false): void
    {
        if ($volatile && ! $this->conn->transport->writable) {
            return;
        }
        if (isset($encodedPackets['nsp'])) {
            unset($encodedPackets['nsp']);
        }
        foreach ($encodedPackets as $packet) {
            $this->conn->write($packet);
        }
    }

    /**
     * Called with incoming transport data.
     *
     * @api private
     */
    public function ondata(string $data): void
    {
        try {
            // todo chek '2["chat message","2"]' . "\0" . ''
            $this->decoder->add(trim($data));
        } catch (Exception $e) {
            $this->onerror($e);
        }
    }

    /**
     * Called when parser fully decodes a packet.
     *
     * @api private
     */
    public function ondecoded(array $packet): void
    {
        if (Parser::CONNECT == $packet['type']) {
            $this->connect($packet['nsp']);
        } else {
            if (isset($this->nsps[$packet['nsp']])) {
                $this->nsps[$packet['nsp']]->onpacket($packet);
            }
        }
    }

    /**
     * Handles an error.
     *
     * @param {Objcet} error object
     * @api   private
     */
    public function onerror($err): void
    {
        foreach ($this->sockets as $socket) {
            $socket->onerror($err);
        }
        $this->onclose($this->id, 'client error');
    }

    /**
     * Called upon transport close. Registered as the listener for the
     * underlying Engine\Socket's 'close' event, which emits
     * ($id, $reason, $description) -- $id is unused here (this Client is
     * already scoped to one connection) but kept as the first parameter so
     * the signature matches what's actually emitted.
     *
     * @api private
     */
    public function onclose($id, string $reason = '', ?string $description = null): void
    {
        if (empty($this->conn)) {
            return;
        }
        // ignore a potential subsequent `close` event
        $this->destroy();

        // `nsps` and `sockets` are cleaned up seamlessly
        foreach ($this->sockets as $socket) {
            $socket->onclose($reason);
        }
        $this->sockets = [];
    }

    /**
     * Cleans up event listeners.
     *
     * @api private
     */
    public function destroy(): void
    {
        if (! $this->conn) {
            return;
        }
        $this->conn->removeAllListeners();
        $this->decoder->removeAllListeners();
        $this->encoder->removeAllListeners();
        $this->server = $this->conn = $this->encoder = $this->decoder = $this->request = null;
        $this->nsps = [];
    }
}
