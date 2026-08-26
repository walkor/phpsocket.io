<?php

namespace PHPSocketIO;

use Workerman\Worker;
use PHPSocketIO\Engine\Engine;
use PHPSocketIO\Event\Emitter;

class SocketIO
{
    // Intentionally untyped: tests stand in a lightweight double for the
    // real Workerman\Worker (constructing a real one requires an actual
    // event loop).
    /** @var object|null */
    public $worker;
    public ?Nsp $sockets = null;
    /** @var array<string, Nsp> */
    public array $nsps = [];
    protected ?string $_nsp = null;
    protected ?string $_socket = null;
    protected ?string $_adapter = null;
    public ?Engine $engine = null;
    protected string $_origins = '*:*';

    /**
     * @param array<string, mixed> $opts
     */
    public function __construct(?int $port = null, array $opts = [])
    {
        $nsp = $opts['nsp'] ?? '\PHPSocketIO\Nsp';
        $this->nsp($nsp);

        $socket = $opts['socket'] ?? '\PHPSocketIO\Socket';
        $this->socket($socket);

        $adapter = $opts['adapter'] ?? '\PHPSocketIO\DefaultAdapter';
        $this->adapter($adapter);
        if (isset($opts['origins'])) {
            $this->origins($opts['origins']);
        }

        unset($opts['nsp'], $opts['socket'], $opts['adapter'], $opts['origins']);

        $this->sockets = $this->of('/');

        if (! class_exists('Protocols\SocketIO')) {
            class_alias('PHPSocketIO\Engine\Protocols\SocketIO', 'Protocols\SocketIO');
        }
        if ($port) {
            $host = '0.0.0.0';
            if (isset($opts['host'])) {
                $ip = trim($opts['host'], '[]');
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $host = (strpos($ip, ':') !== false) ? "[$ip]" : $ip;
                }
            }
            $worker = new Worker('SocketIO://' . $host . ':' . $port, $opts);
            $worker->name = 'PHPSocketIO';

            if (isset($opts['ssl'])) {
                $worker->transport = 'ssl';
            }

            $this->attach($worker);
        }
    }

    /**
     * @return string|static|null
     */
    public function nsp(?string $v = null)
    {
        if (empty($v)) {
            return $this->_nsp;
        }
        $this->_nsp = $v;
        return $this;
    }

    /**
     * @return string|static|null
     */
    public function socket(?string $v = null)
    {
        if (empty($v)) {
            return $this->_socket;
        }
        $this->_socket = $v;
        return $this;
    }

    /**
     * @return string|static|null
     */
    public function adapter(?string $v = null)
    {
        if (empty($v)) {
            return $this->_adapter;
        }
        $this->_adapter = $v;
        foreach ($this->nsps as $nsp) {
            $nsp->initAdapter();
        }
        return $this;
    }

    /**
     * @return string|static
     */
    public function origins(?string $v = null)
    {
        if ($v === null) {
            return $this->_origins;
        }
        $this->_origins = $v;
        if (isset($this->engine)) {
            $this->engine->origins = $this->_origins;
        }
        return $this;
    }

    /**
     * @param array<string, mixed> $opts
     */
    public function attach(Worker $srv, array $opts = []): SocketIO
    {
        $engine = new Engine();
        $engine->attach($srv, $opts);

        // Export http server
        $this->worker = $srv;

        // bind to engine events
        $this->bind($engine);

        return $this;
    }

    public function bind(Engine $engine): SocketIO
    {
        $this->engine = $engine;
        $this->engine->on('connection', [$this, 'onConnection']);
        $this->engine->origins = $this->_origins;
        return $this;
    }

    public function of(string $name, ?callable $fn = null): Nsp
    {
        if ($name[0] !== '/') {
            $name = "/$name";
        }
        if (empty($this->nsps[$name])) {
            $nsp_name = $this->nsp();
            $this->nsps[$name] = new $nsp_name($this, $name);
        }
        if ($fn) {
            $this->nsps[$name]->on('connect', $fn);
        }
        return $this->nsps[$name];
    }

    public function onConnection(Emitter $engine_socket): SocketIO
    {
        $client = new Client($this, $engine_socket);
        $client->connect('/');
        return $this;
    }

    public function on(): ?Emitter
    {
        $args = array_pad(func_get_args(), 2, null);

        if ($args[0] === 'workerStart') {
            $this->worker->onWorkerStart = $args[1];
        } elseif ($args[0] === 'workerStop') {
            $this->worker->onWorkerStop = $args[1];
        } elseif ($args[0] !== null) {
            return call_user_func_array([$this->sockets, 'on'], $args);
        }
        return null;
    }

    public function in(): Nsp
    {
        return call_user_func_array([$this->sockets, 'in'], func_get_args());
    }

    public function to(): Nsp
    {
        return call_user_func_array([$this->sockets, 'to'], func_get_args());
    }

    /**
     * @return Nsp|void
     */
    public function emit()
    {
        return call_user_func_array([$this->sockets, 'emit'], func_get_args());
    }

    public function send(): Nsp
    {
        return call_user_func_array([$this->sockets, 'send'], func_get_args());
    }

    public function write(): Nsp
    {
        return call_user_func_array([$this->sockets, 'write'], func_get_args());
    }
}
