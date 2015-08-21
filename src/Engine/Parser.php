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

    public satic $packetsList = array(
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
        $data = !isset($packet['data') ? 'undefined' : $packet['data'];
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

    
}
