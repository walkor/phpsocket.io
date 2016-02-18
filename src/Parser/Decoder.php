<?php
namespace PHPSocketIO\Parser;
use \PHPSocketIO\Parser\Parser;
use \PHPSocketIO\Event\Emitter;
use \PHPSocketIO\Debug;
class Decoder extends Emitter 
{
public function __construct()
{
    Debug::debug('Decoder __construct');
}

public function __destruct()
{
    Debug::debug('Decoder __destruct');
}

    public function add($obj)
    {
      if (is_string($obj))
      {
          $packet = self::decodeString($obj);
          if(Parser::BINARY_EVENT == $packet['type'] || Parser::BINARY_ACK == $packet['type'])
          { 
               // binary packet's json todo BinaryReconstructor
               $this->reconstructor = new BinaryReconstructor(packet);

               // no attachments, labeled binary but no binary data to follow
               if ($this->reconstructor->reconPack->attachments === 0) 
               {
                   $this->emit('decoded', $packet);
               }
           } else { // non-binary full packet
               $this->emit('decoded', $packet);
           }
       }
       else if (isBuf(obj) || !empty($obj['base64']))
       { // raw binary data
            if (!$this->reconstructor) 
            {
                throw new \Exception('got binary data when not reconstructing a packet');
            } else {
                $packet = $this->reconstructor->takeBinaryData($obj);
                if ($packet)
                { // received final buffer
                    $this->reconstructor = null;
                    $this->emit('decoded', $packet);
                }
            }
        }
        else {
            throw new \Exception('Unknown type: ' + obj);
        }
    }

    public function decodeString($str) 
    {
        $p = array();
        $i = 0;

        // look up type
        $p['type'] = $str[0];
        if(!isset(Parser::$types[$p['type']])) return self::error();

        // look up attachments if type binary
        if(Parser::BINARY_EVENT == $p['type'] || Parser::BINARY_ACK == $p['type'])
        {
            $buf = '';
            while ($str[++$i] != '-')
            {
                $buf .= $str[$i];
                if($i == strlen(str)) break;
            }
            if ($buf != intval($buf) || $str[$i] != '-')
            {
                throw new \Exception('Illegal attachments');
            }
            $p['attachments'] = intval($buf);
        }

        // look up namespace (if any)
        if(isset($str[$i + 1]) && '/' === $str[$i + 1])
        {
            $p['nsp'] = '';
            while (++$i)
            {
                if ($i === strlen($str)) break;
                $c = $str[$i];
                if (',' === $c) break;
                $p['nsp'] .= $c;
            }
        } else {
            $p['nsp'] = '/';
        }

        // look up id
        if(isset($str[$i+1]))
        {
            $next = $str[$i+1];
            if ('' !== $next && strval((int)$next) === strval($next))
            {
                $p['id'] = '';
                while (++$i)
                {
                    $c = $str[$i];
                    if (null == $c || strval((int)$c) != strval($c))
                    {
                        --$i;
                        break;
                    }
                    $p['id'] .= $str[$i];
                    if($i == strlen($str)) break;
                }
                $p['id'] = (int)$p['id'];
            }
        }

        // look up json data
        if (isset($str[++$i]))
        {
            // todo try
            $p['data'] = json_decode(substr($str, $i), true);
        }

        return $p;
    }
    
    public static function error()
    {
         return array(
            'type'=> Parser::ERROR,
            'data'=> 'parser error'
         );
    }

    public function destroy()
    {

    }
}
