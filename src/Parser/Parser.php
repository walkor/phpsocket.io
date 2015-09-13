<?php
namespace PHPSocketIO\Parser;
class Parser 
{
/**
 * Packet type `connect`.
 *
 * @api public
 */
    const CONNECT = 0;

/**
 * Packet type `disconnect`.
 *
 * @api public
 */
    const DISCONNECT = 1;

/**
 * Packet type `event`.
 *
 * @api public
 */
    const EVENT = 2;

/**
 * Packet type `ack`.
 *
 * @api public
 */
    const ACK = 3;

/**
 * Packet type `error`.
 *
 * @api public
 */
    const ERROR = 4;

/**
 * Packet type 'binary event'
 *
 * @api public
 */
    const BINARY_EVENT = 5;

/**
 * Packet type `binary ack`. For acks with binary arguments.
 *
 * @api public
 */
    const BINARY_ACK = 6;
 
    public static $types = array(
      'CONNECT',
      'DISCONNECT',
      'EVENT',
      'BINARY_EVENT',
      'ACK',
      'BINARY_ACK',
      'ERROR'
    );
}
