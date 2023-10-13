<?php

namespace PHPSocketIO\Engine;

use Exception;
use PHPSocketIO\Debug;

class Parser
{
    public function __construct()
    {
        Debug::debug('Engine/Parser __construct');
    }

    public static $packets = [
        'open' => 0,     // non-ws
        'close' => 1,    // non-ws
        'ping' => 2,
        'pong' => 3,
        'message' => 4,
        'upgrade' => 5,
        'noop' => 6,
    ];

    public static $packetsList = [
        'open',
        'close',
        'ping',
        'pong',
        'message',
        'upgrade',
        'noop'
    ];

    public static $err = [
        'type' => 'error',
        'data' => 'parser error'
    ];

    public static function encodePacket($packet): string
    {
        $data = ! isset($packet['data']) ? '' : $packet['data'];
        return self::$packets[$packet['type']] . $data;
    }

    /**
     * Decodes a packet. Data also available as an ArrayBuffer if requested.
     *
     * @return array|string[] {Object} with `type` and `data` (if any)
     */
    public static function decodePacket(string $data): array
    {
        if ($data[0] === 'b') {
            return self::decodeBase64Packet(substr($data, 1));
        }

        $type = $data[0];
        if (! isset(self::$packetsList[$type])) {
            return self::$err;
        }

        if (isset($data[1])) {
            return ['type' => self::$packetsList[$type], 'data' => substr($data, 1)];
        } else {
            return ['type' => self::$packetsList[$type]];
        }
    }

    /**
     * Decodes a packet encoded in a base64 string.
     *
     * @param $msg
     * @return array {Object} with `type` and `data` (if any)
     */
    public static function decodeBase64Packet($msg): array
    {
        $type = self::$packetsList[$msg[0]];
        $data = base64_decode(substr($msg, 1));
        return ['type' => $type, 'data' => $data];
    }

    /**
     * Encodes multiple messages (payload).
     *
     *     <length>:data
     *
     * Example:
     *
     *     11:hello world2:hi
     *
     * If any contents are binary, they will be encoded as base64 strings. Base64
     * encoded strings are marked with a b before the length specifier
     *
     * @param {Array} packets
     * @api   private
     */
    public static function encodePayload($packets, $supportsBinary = null): string
    {
        if ($supportsBinary) {
            return self::encodePayloadAsBinary($packets);
        }

        if (! $packets) {
            return '0:';
        }

        $results = '';
        foreach ($packets as $msg) {
            $results .= self::encodeOne($msg);
        }
        return $results;
    }

    public static function encodeOne($packet): string
    {
        $message = self::encodePacket($packet);
        return strlen($message) . ':' . $message;
    }

    /*
     * Decodes data when a payload is maybe expected. Possible binary contents are
    * decoded from their base64 representation
    *
    * @api public
    */
    public static function decodePayload($data, $binaryType = null)
    {
        if (! preg_match('/^\d+:\d/', $data)) {
            return self::decodePayloadAsBinary($data, $binaryType);
        }

        if ($data === '') {
            // parser error - ignoring payload
            return self::$err;
        }

        $length = '';//, n, msg;

        for ($i = 0, $l = strlen($data); $i < $l; $i++) {
            $chr = $data[$i];

            if (':' != $chr) {
                $length .= $chr;
            } else {
                if ('' == $length || ($length != ($n = intval($length)))) {
                    // parser error - ignoring payload
                    return self::$err;
                }

                $msg = substr($data, $i + 1);

                if (isset($msg[0])) {
                    $packet = self::decodePacket($msg);

                    if (self::$err['type'] == $packet['type'] && self::$err['data'] == $packet['data']) {
                        // parser error in individual packet - ignoring payload
                        return self::$err;
                    }

                    return $packet;
                }

                // advance cursor
                $i += $n;
                $length = '';
            }
        }

        if ($length !== '') {
            // parser error - ignoring payload
            echo new Exception('parser error');
            return self::$err;
        }
    }

    /**
     * Encodes multiple messages (payload) as binary.
     *
     * <1 = binary, 0 = string><number from 0-9><number from 0-9>[...]<number
     * 255><data>
     *
     * Example:
     * 1 3 255 1 2 3, if the binary contents are interpreted as 8-bit integers
     *
     * @param  {Array} packets
     * @return string {Buffer} encoded payload
     * @api    private
     */
    public static function encodePayloadAsBinary($packets): string
    {
        $results = '';
        foreach ($packets as $msg) {
            $results .= self::encodeOneAsBinary($msg);
        }
        return $results;
    }

    public static function encodeOneAsBinary($p): string
    {
        $packet = self::encodePacket($p);
        $encodingLength = '' . strlen($packet);
        $sizeBuffer = chr(0);
        for ($i = 0; $i < strlen($encodingLength); $i++) {
            $sizeBuffer .= chr($encodingLength[$i]);
        }
        $sizeBuffer .= chr(255);
        return $sizeBuffer . $packet;
    }

    /*
     * Decodes data when a payload is maybe expected. Strings are decoded by
    * interpreting each byte as a key code for entries marked to start with 0. See
    * description of encodePayloadAsBinary
    * @api public
    */
    public static function decodePayloadAsBinary($data, $binaryType = null): array
    {
        $bufferTail = $data;
        $buffers = [];

        while (strlen($bufferTail) > 0) {
            $strLen = '';
            $numberTooLong = false;
            for ($i = 1;; $i++) {
                $tail = ord($bufferTail[$i]);
                if ($tail === 255) {
                    break;
                }
                // 310 = char length of Number.MAX_VALUE
                if (strlen($strLen) > 310) {
                    $numberTooLong = true;
                    break;
                }
                $strLen .= $tail;
            }
            if ($numberTooLong) {
                return self::$err;
            }
            $bufferTail = substr($bufferTail, strlen($strLen) + 1);

            $msgLength = intval($strLen);

            $msg = substr($bufferTail, 1, $msgLength + 1);
            $buffers[] = $msg;
            $bufferTail = substr($bufferTail, $msgLength + 1);
        }
        $packets = [];
        foreach ($buffers as $i => $buffer) {
            $packets[] = self::decodePacket($buffer);
        }
        return $packets;
    }
}
