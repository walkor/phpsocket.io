<?php
namespace Engine;
class Parser
{
    public static $packets=array(
        'open'=>     0    // non-ws
      , 'close'=>    1    // non-ws
      , 'ping'=>     2
      , 'pong'=>     3
      , 'message'=>  4
      , 'upgrade'=>  5
      , 'noop'=>     6
    );

    public static $packetsList = array(
       'open', 
       'close',
       'ping',
       'pong',
       'message',
       'upgrade',
       'noop'
    );

    public static $err = array(
        'type' => 'error', 
        'data' => 'parser error'
    );

    public static function encodePacket($packet, $supportsBinary = null, $utf8encode = null, $callback = null)
    {
        if(is_callable($supportsBinary))
        {
            $callback = $supportsBinary;
            $supportsBinary = null;
        }

        if (is_callable($utf8encode))
        {
            $callback = $utf8encode;
            $utf8encode = null;
        }
        // todo $packet['data']['buffer'] ???
        $data = !isset($packet['data']) ? '' : $packet['data'];
/*
  if (Buffer.isBuffer(data)) {
    return encodeBuffer(packet, supportsBinary, callback);
  } else if (data instanceof ArrayBuffer) {
    return encodeArrayBuffer(packet, supportsBinary, callback);
  }
*/
        if(strlen($data)>1)
        {
            return self::encodeBuffer($packet, $supportsBinary, $callback);
        }

        // Sending data as a utf-8 string
        $encoded = self::$packets[$packet['type']].$data;
        return call_user_func($callback, $encoded);
    }

    public static function encodeBuffer($packet, $supportsBinary, $callback) 
    {
        $data = $packet['data'];
        if (!$supportsBinary) 
        {
            return self::encodeBase64Packet($packet, $callback);
        }
        $type_buffer = self::$packets[$packet['type']].$data;
        return call_user_func($callback, $type_buffer);
    }
    
    public static function encodeArrayBuffer($packet, $supportsBinary, $callback) 
    {
        $data = isset($packet['data'])  ? 'undefined' : $packet['data'];
        if (!$supportsBinary) 
        {
            return self::encodeBase64Packet($packet, $callback);
        }
        $result_buffer = self::$packets[$packet['type']].$data;
        return call_user_func($callback, $result_buffer);
    }
    
    /**
     * Encodes a packet with binary data in a base64 string
     *
     * @param {Object} packet, has `type` and `data`
     * @return {String} base64 encoded message
     */
    
    public static function encodeBase64Packet($packet, $callback)
    {
        $data = isset($packet['data'])  ? 'undefined' : $packet['data'];
        $message = 'b' . self::$packets[$packet['type']] . base64_encode($packet['data']);
        return call_user_func($callback, $message);
    }
    
    /**
     * Decodes a packet. Data also available as an ArrayBuffer if requested.
     *
     * @return {Object} with `type` and `data` (if any)
     * @api private
     */
    
    public static function decodePacket($data, $binaryType, $utf8decode) 
    {
        // String data todo check if (typeof data == 'string' || data === undefined) 
            if ($data[0] === 'b') 
            {
                return self::decodeBase64Packet(substr($data, 1), $binaryType);
            }
    
            $type = $data[0];
            if (!isset(self::$packetsList[$type]))
            {
                return self::$err;
            }
    
            if (isset($data[1])) 
            {
                return array('type'=> self::$packetsList[$type], 'data'=> substr($data, 1));
            } 
            else 
            {
                return array('type'=> self::$packetsList[$type]);
            }
    }
    
    /**
     * Decodes a packet encoded in a base64 string.
     *
     * @param {String} base64 encoded message
     * @return {Object} with `type` and `data` (if any)
     */
    
    public static function decodeBase64Packet($msg, $binaryType) 
    {
        $type = self::$packetsList[$msg[0]];
        $data = base64_decode(substr($data, 1));
        return array('type'=> $type, 'data'=> $data);
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
     * @api private
     */
    
    public static function encodePayload($packets, $supportsBinary = null, $callback = null) 
    {
        if (is_callable($supportsBinary))
        {
            $callback = $supportsBinary;
            $supportsBinary = null;
        }
    
        if ($supportsBinary) 
        {
            return self::encodePayloadAsBinary($packets, $callback);
        }
    
        if (!$packets) 
        {
            return call_user_func($callback, '0:');
        }
        
        self::map($packets, '\Engine\Parser::encodeOne', function($err, $results)
        {
            return call_user_func($callback, implode('', $results));
        });
    }
    
    public static function setLengthHeader($message) 
    {
        return strlen(message) . ':' . $message;
    }
    
    public static function encodeOne($packet, $doneCallback, $supportsBinary = null, $result = null) 
    {
        self::encodePacket($packet, $supportsBinary, true, function($message) 
        {
            call_user_func($doneCallback, null, self::setLengthHeader($message));
        });
    }
    
    
    
    /**
     * Async array map using after
     */
    
    public static function map($ary, $each, $done) 
    {
        $results = array();
        $len = count($ary);
        $ary = array_values($ary);
        for($i = 0; $i < $len; $i++)
        {
            $msg = $ary[$i];
            if($i === $len -1)
            {
                call_user_func($each, $msg, function($err, $msg)use(&$results, $done)
                {
                    $results[] = $msg;
                    call_user_func($done, null, $results);
                });
            }
            else
            {
                call_user_func($each, $msg, function($err, $msg)use(&$results)
                {
                    $results[] = $msg;
                });
            }
        } 
    }
    
    /*
     * Decodes data when a payload is maybe expected. Possible binary contents are
    * decoded from their base64 representation
    *
    * @param {String} data, callback method
    * @api public
    */
    
    public static function decodePayload($data, $binaryType = null, $callback = null) 
    {
        //if (!is_string($data))
        {
            return self::decodePayloadAsBinary($data, $binaryType, $callback);
        }
    
        if (is_callable($binaryType))
        {
            $callback = $binaryType;
            $binaryType = null;
        }
    
        if ($data === '') 
        {
            // parser error - ignoring payload
            return call_user_func($callback, self::err, 0, 1);
        }
    
        $length = '';//, n, msg;
    
        for ($i = 0, $l = strlen($data); $i < $l; $i++) 
        {
            $chr = $data[$i];
    
            if (':' != $chr) 
            {
                $length .= $chr;
            } 
            else 
            {
                if ('' == $length || ($length != ($n = intval($length)))) 
                {
                    // parser error - ignoring payload
                    return call_user_func($callback, self::$err, 0, 1);
                }
    
                $msg = substr($data, $i + 1, $n);
    
                if ($length != strlen($msg)) 
                {
                    // parser error - ignoring payload
                    return call_user_func($callback, self::$err, 0, 1);
                }
    
                if (isset($msg[0])) 
                {
                    $packet = self::decodePacket($msg, $binaryType, true);
    
                    if (self::$err['type'] == $packet['type'] && self::$err['data'] == $packet['data']) 
                    {
                        // parser error in individual packet - ignoring payload
                        return call_user_func($callback, self::$err, 0, 1);
                    }
    
                    $ret = call_user_func($callback, $packet,$i + $n, $l);
                    if (false === $ret) return;
                }
    
                // advance cursor
                $i += $n;
                $length = '';
            }
        }
    
        if ($length !== '') 
        {
            // parser error - ignoring payload
            echo new \Exception('parser error');
            return call_user_func($callback, self::$err, 0, 1);
        }
    }
    
    /**
     * Encodes multiple messages (payload) as binary.
     *
     * <1 = binary, 0 = string><number from 0-9><number from 0-9>[...]<number
     * 255><data>
     *
     * Example:
     * 1 3 255 1 2 3, if the binary contents are interpreted as 8 bit integers
     *
     * @param {Array} packets
     * @return {Buffer} encoded payload
     * @api private
     */
    
    public static function encodePayloadAsBinary($packets, $callback) 
    {
        if (!$packets) 
        {
            return call_user_func($callback, '');
        }
    
        self::map($packets, '\Engine\Parser::encodeOneAsBinary', function($err, $results)use($callback) 
        {
            return call_user_func($callback, implode('', $results));
        });
    }
    
    public static function encodeOneAsBinary($p, $doneCallback) 
    {
        // todo is string or arraybuf
        self::encodePacket($p, true, true, function($packet)use($doneCallback) 
        {
            $encodingLength = ''.strlen($packet);
            $sizeBuffer = chr(0);
            for ($i = 0; $i < strlen($encodingLength); $i++) 
            {
                $sizeBuffer .= chr($encodingLength[$i]);
            }
            $sizeBuffer .= chr(255);
            return call_user_func($doneCallback, null, $sizeBuffer.$packet);
        });
    }
    
    /*
     * Decodes data when a payload is maybe expected. Strings are decoded by
    * interpreting each byte as a key code for entries marked to start with 0. See
    * description of encodePayloadAsBinary
    * @param {Buffer} data, callback method
    * @api public
    */
    
    public static function decodePayloadAsBinary($data, $binaryType = null, $callback = null) 
    {
        if (is_callable($binaryType))
        {
            $callback = $binaryType;
            $binaryType = null;
        }
    
        $bufferTail = $data;
        $buffers = array();
    
        while (strlen($bufferTail) > 0) 
        {
            $strLen = '';
            $isString = $bufferTail[0] == 0;
            $numberTooLong = false;
            for ($i = 1; ; $i++) 
            {
                $tail = ord($bufferTail[$i]);
                if ($tail === 255)  break;
                // 310 = char length of Number.MAX_VALUE
                if (strlen($strLen) > 310) 
                {
                    $numberTooLong = true;
                    break;
                }
                $strLen .= $tail;
            }
            if($numberTooLong) return call_user_func($callback, self::$err, 0, 1);
            $bufferTail = substr($bufferTail, strlen($strLen) + 1);
    
            $msgLength = intval($strLen, 10);
    
            $msg = substr($bufferTail, 1, $msgLength + 1);
            $buffers[] = $msg;
            $bufferTail = substr($bufferTail, $msgLength + 1);
        }
        $total = count($buffers);
        foreach($buffers as $i => $buffer)
        {
            call_user_func($callback, self::decodePacket($buffer, $binaryType, true), $i, $total);
        }
    }
}
