<?php

namespace PHPSocketIO\Parser;

use Exception;
use PHPSocketIO\Event\Emitter;
use PHPSocketIO\Debug;

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

    /**
     * @throws Exception
     */
    public function add($obj): void
    {
        if (is_string($obj)) {
            $packet = self::decodeString($obj);
            $this->emit('decoded', $packet);
        }
    }

    /**
     * @throws Exception
     */
    public function decodeString($str): array
    {
        $p = [];
        $i = 0;

        // look up type
        $p['type'] = $str[0];
        if (! isset(Parser::$types[$p['type']])) {
            return self::error();
        }

        // look up attachments if type binary
        if (Parser::BINARY_EVENT == $p['type'] || Parser::BINARY_ACK == $p['type']) {
            $buf = '';
            while ($str[++$i] != '-') {
                $buf .= $str[$i];
                if ($i == strlen($str)) {
                    break;
                }
            }
            if ($buf != intval($buf) || $str[$i] != '-') {
                throw new Exception('Illegal attachments');
            }
            $p['attachments'] = intval($buf);
        }

        // look up namespace (if any)
        if (isset($str[$i + 1]) && '/' === $str[$i + 1]) {
            $p['nsp'] = '';
            while (++$i) {
                if ($i === strlen($str)) {
                    break;
                }
                $c = $str[$i];
                if (',' === $c) {
                    break;
                }
                $p['nsp'] .= $c;
            }
        } else {
            $p['nsp'] = '/';
        }

        // look up id
        if (isset($str[$i + 1])) {
            $next = $str[$i + 1];
            if ('' !== $next && strval((int)$next) === strval($next)) {
                $p['id'] = '';
                while (++$i) {
                    $c = $str[$i];
                    if (null == $c || strval((int)$c) != strval($c)) {
                        --$i;
                        break;
                    }
                    $p['id'] .= $str[$i];
                    if ($i == strlen($str)) {
                        break;
                    }
                }
                $p['id'] = (int)$p['id'];
            }
        }

        // look up json data
        if (isset($str[++$i])) {
            $p['data'] = json_decode(substr($str, $i), true);
        }

        return $p;
    }

    public static function error(): array
    {
        return [
            'type' => Parser::ERROR,
            'data' => 'parser error'
        ];
    }
}
