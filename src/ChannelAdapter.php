<?php

namespace PHPSocketIO;

use Exception;
use RuntimeException;
use Workerman\Worker;

class ChannelAdapter extends DefaultAdapter
{
    protected ?string $_channelId = null;

    // "socket.io#<nsp>#": the namespace's default channel, and the prefix of its room channels.
    protected string $_channelPrefix = 'socket.io#/#';

    public static string $ip = '127.0.0.1';

    public static int $port = 2206;

    /**
     * @param object $nsp
     * @throws Exception
     */
    public function __construct($nsp)
    {
        // Connecting before the loop runs fails, or would share one connection across forked workers.
        /** @var \Workerman\Events\EventInterface|null $loop Workerman's docblock omits null (no loop yet). */
        $loop = Worker::getEventLoop();
        if ($loop === null) {
            throw new RuntimeException(
                'ChannelAdapter needs a running Workerman event loop. Set it in workerStart: '
                . '$io->on(\'workerStart\', function () use ($io) { '
                . '$io->adapter(\\PHPSocketIO\\ChannelAdapter::class); });'
            );
        }
        parent::__construct($nsp);
        $this->_channelId = (function_exists('random_int') ? random_int(1, 10000000) : rand(1, 10000000)) . "-" . (function_exists('posix_getpid') ? posix_getpid() : 1);
        $this->_channelPrefix = "socket.io#{$this->nsp->name}#";
        \Channel\Client::connect(self::$ip, self::$port);
        // Absorbs messages still in flight for a just-unsubscribed channel, which would otherwise throw.
        if (\Channel\Client::$onMessage === null) {
            \Channel\Client::$onMessage = static function (): void {
            };
        }
        $this->subscribe($this->_channelPrefix);
    }

    // Per-channel callback: \Channel\Client::$onMessage is process-wide, so one per namespace can't share it.
    private function subscribe(string $channel): void
    {
        \Channel\Client::on($channel, function ($msg) use ($channel): void {
            $this->onChannelMessage($channel, $msg);
        });
    }

    // Detects a socket's auto-joined self-sid room (24 hex chars, optionally
    // "$nsp#"-prefixed) so it skips a dedicated channel -- see issue #303.
    private static function isSidRoom(string $room): bool
    {
        return (bool) preg_match('/^(?:.*#)?[0-9a-f]{24}$/', $room);
    }

    public function add(string $id, string $room): void
    {
        $this->sids[$id][$room] = true;
        $this->rooms[$room][$id] = true;
        if (self::isSidRoom($room)) {
            return;
        }
        $this->subscribe("{$this->_channelPrefix}$room#");
    }

    public function del(string $id, string $room): void
    {
        unset($this->sids[$id][$room]);
        unset($this->rooms[$room][$id]);
        if (empty($this->rooms[$room])) {
            unset($this->rooms[$room]);
            if (! self::isSidRoom($room)) {
                \Channel\Client::unsubscribe("{$this->_channelPrefix}$room#");
            }
        }
    }

    public function delAll(string $id): void
    {
        $rooms = isset($this->sids[$id]) ? array_keys($this->sids[$id]) : [];
        if ($rooms) {
            foreach ($rooms as $room) {
                unset($this->rooms[$room][$id]);
                if (isset($this->rooms[$room]) && empty($this->rooms[$room])) {
                    unset($this->rooms[$room]);
                    // Channel subscriptions aren't refcounted: only drop once no local socket remains.
                    if (! self::isSidRoom($room)) {
                        \Channel\Client::unsubscribe("{$this->_channelPrefix}$room#");
                    }
                }
            }
        }
        unset($this->sids[$id]);
    }

    /**
     * @param array<int, mixed> $msg
     */
    public function onChannelMessage(string $channel, array $msg): void
    {
        if ($this->_channelId === array_shift($msg)) {
            return;
        }

        $packet = $msg[0];

        $opts = $msg[1];

        if (! $packet) {
            return;
        }

        if (empty($packet['nsp'])) {
            $packet['nsp'] = '/';
        }

        if ($packet['nsp'] != $this->nsp->name) {
            return;
        }

        $this->broadcast($packet, $opts, true);
    }

    /**
     * Channel names to publish a room-targeted broadcast to: real rooms each
     * get their own channel; any sid-room sends the whole broadcast once on
     * the shared default channel instead, since every worker subscribes to it
     * and filters by all rooms locally (publishing to both would deliver twice).
     *
     * @param array<int, string> $rooms
     * @return array<int, string>
     */
    private static function resolveChannelsForRooms(string $prefix, array $rooms): array
    {
        $channels = [];
        foreach ($rooms as $room) {
            if (self::isSidRoom($room)) {
                return [$prefix];
            }
            $channels[] = "$prefix$room#";
        }
        return $channels;
    }

    /**
     * @param array<string, mixed> $packet
     * @param array<string, mixed> $opts
     */
    public function broadcast(array $packet, array $opts, bool $remote = false): void
    {
        parent::broadcast($packet, $opts);
        if (! $remote) {
            $packet['nsp'] = $this->nsp->name;
            $msg = [$this->_channelId, $packet, $opts];
            $channels = empty($opts['rooms'])
                ? [$this->_channelPrefix]
                : self::resolveChannelsForRooms($this->_channelPrefix, $opts['rooms']);
            foreach ($channels as $chn) {
                \Channel\Client::publish($chn, $msg);
            }
        }
    }
}
