<?php

namespace PHPSocketIO;

class DefaultAdapter
{
    // Intentionally untyped: accepts any nsp-like object exposing ->name
    // and ->connected (tests use lightweight duck-typed fakes here instead
    // of a real Nsp).
    public $nsp = null;
    public array $rooms = [];
    public array $sids = [];
    public ?Parser\Encoder $encoder = null;

    public function __construct($nsp)
    {
        $this->nsp = $nsp;
        $this->encoder = new Parser\Encoder();
    }

    public function add(string $id, string $room): void
    {
        $this->sids[$id][$room] = true;
        $this->rooms[$room][$id] = true;
    }

    public function del(string $id, string $room): void
    {
        unset($this->sids[$id][$room]);
        unset($this->rooms[$room][$id]);
        if (empty($this->rooms[$room])) {
            unset($this->rooms[$room]);
        }
    }

    public function delAll(string $id): void
    {
        $rooms = array_keys($this->sids[$id] ?? []);
        foreach ($rooms as $room) {
            $this->del($id, $room);
        }
        unset($this->sids[$id]);
    }

    public function broadcast(array $packet, array $opts, bool $remote = false): void
    {
        $rooms = $opts['rooms'] ?? [];
        $except = $opts['except'] ?? [];
        $flags = $opts['flags'] ?? [];
        $packetOpts = [
            'preEncoded' => true,
            'volatile' => $flags['volatile'] ?? null,
            'compress' => $flags['compress'] ?? null
        ];
        $packet['nsp'] = $this->nsp->name;
        $encodedPackets = $this->encoder->encode($packet);
        if ($rooms) {
            $ids = [];
            foreach ($rooms as $i => $room) {
                if (! isset($this->rooms[$room])) {
                    continue;
                }

                $room = $this->rooms[$room];
                foreach ($room as $id => $item) {
                    if (isset($ids[$id]) || isset($except[$id])) {
                        continue;
                    }
                    if (isset($this->nsp->connected[$id])) {
                        $ids[$id] = true;
                        $this->nsp->connected[$id]->packet($encodedPackets, $packetOpts);
                    }
                }
            }
        } else {
            foreach ($this->sids as $id => $sid) {
                if (isset($except[$id])) {
                    continue;
                }
                if (isset($this->nsp->connected[$id])) {
                    $socket = $this->nsp->connected[$id];
                    $volatile = $flags['volatile'] ?? null;
                    $socket->packet($encodedPackets, true, $volatile);
                }
            }
        }
    }

    public function clients(array $rooms, callable $fn): void
    {
        $sids = [];
        foreach ($rooms as $room) {
            $sids = array_merge($sids, $this->rooms[$room] ?? []);
        }
        $fn(array_keys($sids));
    }
}
