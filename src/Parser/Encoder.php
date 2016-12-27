<?php
namespace PHPSocketIO\Parser;
use \PHPSocketIO\Parser\Parser;
use \PHPSocketIO\Event\Emitter;
use \PHPSocketIO\Debug;
class Encoder extends Emitter 
{
    public function __construct()
    {
        Debug::debug('Encoder __construct');
    }

    public function __destruct()
    {
        Debug::debug('Encoder __destruct');
    }


    public function encode($obj)
    {
        if(Parser::BINARY_EVENT == $obj['type'] || Parser::BINARY_ACK == $obj['type']) 
        {
            echo new \Exception("not support BINARY_EVENT BINARY_ACK");
            return array(); 
        }
        else 
        {
            $encoding = self::encodeAsString($obj);
            return array($encoding);
        }
    }

    public static function encodeAsString($obj) {
        $str = '';
        $nsp = false;

        // first is type
        $str .= $obj['type'];

        // attachments if we have them
        if (Parser::BINARY_EVENT == $obj['type'] || Parser::BINARY_ACK == $obj['type']) 
        {
            $str .= $obj['attachments'];
            $str .= '-';
        }

        // if we have a namespace other than `/`
        // we append it followed by a comma `,`
        if (!empty($obj['nsp']) && '/' !== $obj['nsp']) 
        {
            $nsp = true;
            $str .= $obj['nsp'];
        }

        // immediately followed by the id
        if (isset($obj['id'])) 
        {
            if($nsp)
            {
                $str .= ',';
                $nsp = false;
            }
            $str .= $obj['id'];
        }

        // json data
        if(isset($obj['data']))
        {
            if ($nsp) $str .= ',';
            $str .= json_encode($obj['data']);
        }

        return $str;
    }

}
