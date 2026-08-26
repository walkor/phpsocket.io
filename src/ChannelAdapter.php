<?php

namespace PHPSocketIO;

use Exception;

class ChannelAdapter extends DefaultAdapter
{
    protected ?string $_channelId = null;

    public static string $ip = '127.0.0.1';

    public static int $port = 2206;

    /**
     * @param object $nsp
     * @throws Exception
     */
    public function __construct($nsp)
    {
        parent::__construct($nsp);
        $this->_channelId = (function_exists('random_int') ? random_int(1, 10000000) : rand(1, 10000000)) . "-" . (function_exists('posix_getpid') ? posix_getpid() : 1);
        \Channel\Client::connect(self::$ip, self::$port);
        \Channel\Client::$onMessage = [$this, 'onChannelMessage'];
        \Channel\Client::subscribe("socket.io#/#");
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
        $channel = "socket.io#/#$room#";
        \Channel\Client::subscribe($channel);
    }

    public function del(string $id, string $room): void
    {
        unset($this->sids[$id][$room]);
        unset($this->rooms[$room][$id]);
        if (empty($this->rooms[$room])) {
            unset($this->rooms[$room]);
            if (! self::isSidRoom($room)) {
                $channel = "socket.io#/#$room#";
                \Channel\Client::unsubscribe($channel);
            }
        }
    }

    public function delAll(string $id): void
    {
        $rooms = isset($this->sids[$id]) ? array_keys($this->sids[$id]) : [];
        if ($rooms) {
            foreach ($rooms as $room) {
                if (isset($this->rooms[$room][$id])) {
                    unset($this->rooms[$room][$id]);
                    if (! self::isSidRoom($room)) {
                        $channel = "socket.io#/#$room#";
                        \Channel\Client::unsubscribe($channel);
                    }
                }
                if (isset($this->rooms[$room]) && empty($this->rooms[$room])) {
                    unset($this->rooms[$room]);
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
     * get their own channel; sid-rooms are deduped onto the shared default
     * channel every worker already subscribes to.
     *
     * @param array<int, string> $rooms
     * @return array<int, string>
     */
    private static function resolveChannelsForRooms(array $rooms): array
    {
        $channels = [];
        $publishedDefault = false;
        foreach ($rooms as $room) {
            if (self::isSidRoom($room)) {
                if ($publishedDefault) {
                    continue;
                }
                $channels[] = 'socket.io#/#';
                $publishedDefault = true;
                continue;
            }
            $channels[] = "socket.io#/#$room#";
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
            $packet['nsp'] = '/';
            $msg = [$this->_channelId, $packet, $opts];
            $channels = empty($opts['rooms']) ? ['socket.io#/#'] : self::resolveChannelsForRooms($opts['rooms']);
            foreach ($channels as $chn) {
                \Channel\Client::publish($chn, $msg);
            }
        }
    }
}
