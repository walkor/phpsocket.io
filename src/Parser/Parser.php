<?php

namespace PHPSocketIO\Parser;

class Parser
{
    /**
     * Packet type `connect`.
     */
    const CONNECT = 0;

    /**
     * Packet type `disconnect`.
     */
    const DISCONNECT = 1;

    /**
     * Packet type `event`.
     */
    const EVENT = 2;

    /**
     * Packet type `ack`.
     */
    const ACK = 3;

    /**
     * Packet type `error`.
     */
    const ERROR = 4;

    /**
     * Packet type 'binary event'
     */
    const BINARY_EVENT = 5;

    /**
     * Packet type `binary ack`. For acks with binary arguments.
     */
    const BINARY_ACK = 6;

    /** @var array<int, string> */
    public static array $types = [
        'CONNECT',
        'DISCONNECT',
        'EVENT',
        'BINARY_EVENT',
        'ACK',
        'BINARY_ACK',
        'ERROR'
    ];
}
