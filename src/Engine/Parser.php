<?php
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
       'open' => 'open', 
       'close'=> 'close',
       'ping' => 'ping',
       'pong' => 'pong',
       'message' => 'message',
       'upgrade' => 'upgrade',
       'noop' => 'noop'
    );

    public static $err = array(
        'type' => 'error', 
        'data' => 'parser error'
    );

    public static function encodePacket($packet, $supportsBinary = null, $utf8encode = null, $callback = null)
    {
        if(is_callable(supportsBinary))
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
        $data = !isset($packet['data']) ? 'undefined' : $packet['data'];
/*
  if (Buffer.isBuffer(data)) {
    return encodeBuffer(packet, supportsBinary, callback);
  } else if (data instanceof ArrayBuffer) {
    return encodeArrayBuffer(packet, supportsBinary, callback);
  }
*/
        if(is_string($data))
        {
            return self::encodeBuffer($packet, $supportsBinary, $callback);
        }

        // Sending data as a utf-8 string
        $encoded = self::$packets[$packet['type']];

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
    
            if (!isset(self::$packetslist[$type]))
            {
                return self::$err;
            }
    
            if (isset($data[1])) 
            {
                return array('type'=> self::$packetslist[$type], 'data'=> substr($data, 1));
            } 
            else 
            {
                return array('type'=> self::$packetslist[$type]);
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
        $type = self::$packetslist[$msg[0]];
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
    
    public static function encodePayload($packets, $supportsBinary, $callback) 
    {
        if (is_callable($supportsBinary))
        {
            $callback = $supportsBinary;
            $supportsBinary = null;
        }
    
        if (supportsBinary) 
        {
            return self::encodePayloadAsBinary($packets, $callback);
        }
    
        if (!$packets) 
        {
            return call_user_func($callback, '0:');
        }
        
        self::map($packets, 'Parser::encodeOne', function($err, $results)
        {
            return call_user_func($callback, implode('', $results));
        });
    }
    
    public static function setLengthHeader($message) 
    {
        return strlen(message) . ':' . $message;
    }
    
    public static function encodeOne($packet, $doneCallback, $supportsBinary = null) 
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
        $result = array();
        foreach($ary as $msg)
        {
            $result[] = call_user_func($each, $msg);
        } 
        return call_user_func($done, $result);
    }
    
    /*
     * Decodes data when a payload is maybe expected. Possible binary contents are
    * decoded from their base64 representation
    *
    * @param {String} data, callback method
    * @api public
    */
    
    exports.decodePayload = function (data, binaryType, callback) {
        if ('string' != typeof data) {
            return exports.decodePayloadAsBinary(data, binaryType, callback);
        }
    
        if (typeof binaryType === 'function') {
            callback = binaryType;
            binaryType = null;
        }
    
        var packet;
        if (data == '') {
            // parser error - ignoring payload
            return callback(err, 0, 1);
        }
    
        var length = ''
        , n, msg;
    
        for (var i = 0, l = data.length; i < l; i++) {
            var chr = data.charAt(i);
    
            if (':' != chr) {
                length += chr;
            } else {
                if ('' == length || (length != (n = Number(length)))) {
                    // parser error - ignoring payload
                    return callback(err, 0, 1);
                }
    
                msg = data.substr(i + 1, n);
    
                if (length != msg.length) {
                    // parser error - ignoring payload
                    return callback(err, 0, 1);
                }
    
                if (msg.length) {
                    packet = exports.decodePacket(msg, binaryType, true);
    
                    if (err.type == packet.type && err.data == packet.data) {
                        // parser error in individual packet - ignoring payload
                        return callback(err, 0, 1);
                    }
    
                    var ret = callback(packet, i + n, l);
                    if (false === ret) return;
                }
    
                // advance cursor
                i += n;
                length = '';
            }
        }
    
        if (length != '') {
            // parser error - ignoring payload
            return callback(err, 0, 1);
        }
    
    };
    
    /**
     *
     * Converts a buffer to a utf8.js encoded string
     *
     * @api private
     */
    
    function bufferToString(buffer) {
        var str = '';
        for (var i = 0; i < buffer.length; i++) {
            str += String.fromCharCode(buffer[i]);
        }
        return str;
    }
    
    /**
     *
     * Converts a utf8.js encoded string to a buffer
     *
     * @api private
     */
    
    function stringToBuffer(string) {
        var buf = new Buffer(string.length);
        for (var i = 0; i < string.length; i++) {
            buf.writeUInt8(string.charCodeAt(i), i);
        }
        return buf;
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
    
    exports.encodePayloadAsBinary = function (packets, callback) {
        if (!packets.length) {
            return callback(new Buffer(0));
        }
    
        function encodeOne(p, doneCallback) {
            exports.encodePacket(p, true, true, function(packet) {
    
                if (typeof packet === 'string') {
                    var encodingLength = '' + packet.length;
                    var sizeBuffer = new Buffer(encodingLength.length + 2);
                    sizeBuffer[0] = 0; // is a string (not true binary = 0)
                    for (var i = 0; i < encodingLength.length; i++) {
                        sizeBuffer[i + 1] = parseInt(encodingLength[i], 10);
                    }
                    sizeBuffer[sizeBuffer.length - 1] = 255;
                    return doneCallback(null, Buffer.concat([sizeBuffer, stringToBuffer(packet)]));
                }
    
                var encodingLength = '' + packet.length;
                var sizeBuffer = new Buffer(encodingLength.length + 2);
                sizeBuffer[0] = 1; // is binary (true binary = 1)
                for (var i = 0; i < encodingLength.length; i++) {
                    sizeBuffer[i + 1] = parseInt(encodingLength[i], 10);
                }
                sizeBuffer[sizeBuffer.length - 1] = 255;
                doneCallback(null, Buffer.concat([sizeBuffer, packet]));
            });
        }
    
        map(packets, encodeOne, function(err, results) {
            return callback(Buffer.concat(results));
        });
    };
    
    /*
     * Decodes data when a payload is maybe expected. Strings are decoded by
    * interpreting each byte as a key code for entries marked to start with 0. See
    * description of encodePayloadAsBinary
    * @param {Buffer} data, callback method
    * @api public
    */
    
    exports.decodePayloadAsBinary = function (data, binaryType, callback) {
        if (typeof binaryType === 'function') {
            callback = binaryType;
            binaryType = null;
        }
    
        var bufferTail = data;
        var buffers = [];
    
        while (bufferTail.length > 0) {
            var strLen = '';
            var isString = bufferTail[0] === 0;
            var numberTooLong = false;
            for (var i = 1; ; i++) {
                if (bufferTail[i] == 255)  break;
                // 310 = char length of Number.MAX_VALUE
                if (strLen.length > 310) {
                    numberTooLong = true;
                    break;
                }
                strLen += '' + bufferTail[i];
            }
            if(numberTooLong) return callback(err, 0, 1);
            bufferTail = bufferTail.slice(strLen.length + 1);
    
            var msgLength = parseInt(strLen, 10);
    
            var msg = bufferTail.slice(1, msgLength + 1);
            if (isString) msg = bufferToString(msg);
            buffers.push(msg);
            bufferTail = bufferTail.slice(msgLength + 1);
        }
    
        var total = buffers.length;
        buffers.forEach(function(buffer, i) {
            callback(exports.decodePacket(buffer, binaryType, true), i, total);
        });
    };
}
