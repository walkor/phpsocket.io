<?php
namespace Protocols;
use Workerman\Connection\TcpConnection;
class Http2
{
    public static function input($http_buffer, $connection)
    {
        if(!empty($connection->httpRequest))
        {
            return strlen($http_buffer);
        }
        $pos = strpos($http_buffer, "\r\n\r\n"); 
        if(!$pos)
        {
            if(strlen($http_buffer)>=TcpConnection::$maxPackageSize)
            {
                $connection->close("HTTP/1.1 400 bad request\r\n\r\nheader too long");
                return 0;
            }
            return 0;
        }
        $head_len = $pos + 4;
        $raw_head = substr($http_buffer, 0, $head_len);
        $raw_body = substr($http_buffer, $head_len);
        $req = new Request($connection, $raw_head);
        $res = new Response($connection);
        $connection->httpRequest = $req;
        $connection->httpResponse = $res;
        if(!empty($connection->onRequest))
        {
            $connection->consumeRecvBuffer(strlen($http_buffer));
            self::emitRequest($connection, $req, $res);
            
            if($req->method === 'GET')
            {
                self::emitEnd($connection, $req);
                return 0;
            }
            // POST
            if('\Protocols\Http2::onData' !== $connection->onMessage)
            {
                $connection->onMessage = '\Protocols\Http2::onData';
            }
            if(!$raw_body)
            {
                return 0;
            }
            self::onData($connection, $raw_body);
            return 0;
        }
        else
        {
            if($req->method === 'GET')
            {
                return $pos + 4;
            }
            elseif(isset($req->headers['content-length']))
            {
                return $req->headers['content-length'];
            }
            else
            {
                $connection->close("HTTP/1.1 400 bad request\r\n\r\ntrunk not support");
                return 0; 
            }
        }
    }
    
    public static function onData($connection, $data)
    {
        $req = $connection->httpRequest;
        self::emitData($connection, $req, $data);
        if((isset($req->headers['content-length']) && $req->headers['content-length'] <= strlen($data))     
            || $end_pos = strpos($data, "0\r\n\r\n"))
        {
            self::emitEnd($connection, $req);
        }
    }

    protected static function emitRequest($connection, $req, $res)
    {
        try
        {
            call_user_func($connection->onRequest, $req, $res);
        }
        catch(\Exception $e)
        {
            echo $e;
        }
    }
    
    public static function emitClose($connection, $req)
    {
        if(!$req->onClose)
        {
            return;
        }
        try
        {
            call_user_func($req->onClose, $req);
        }
        catch(\Exception $e)
        {
            echo $e;
        }
    }
    
    public static function emitData($connection, $req, $data)
    {
        if($req->onData)
        {
            try
            {
                call_user_func($req->onData, $req, $data);
            }
            catch(\Exception $e)
            {
                echo $e;
            }
        }
    } 
    
    public static function emitEnd($connection, $req)
    {
        if($req->onEnd)
        {
            try
            {
                call_user_func($req->onEnd, $req);
            }
            catch(\Exception $e)
            {
                echo $e;
            }
        }
        $connection->httpRequest = $connection->httpResponse = null;
    }

    public static function encode($buffer, $connection)
    {
        if(!isset($connection->onRequest))
        {
            $connection->httpResponse->setHeader('Content-Length', strlen($buffer));
            return $connection->httpResponse->getHeadBuffer() . $buffer;
        }
        return $buffer;
    }

    public static function decode($http_buffer, $connection)
    {
        if(isset($connection->onRequest))
        {
            return $http_buffer;
        }
        else
        {
            list($head, $body) = explode("\r\n\r\n", $http_buffer, 2);
            return $body;
        }
    }
}

class Request
{
    public $onData = null;

    public $onEnd = null;

    public $httpVersion = null;
    
    public $headers = array();
    
    public $rawHeaders = null;
    
    public $method = null;
    
    public $url = null;
    
    public $connection = null;
    
    public function __construct($connection, $raw_head)
    {
        $this->connection = $connection;
        $this->parseHead($raw_head);
    }
    
    public function parseHead($raw_head)
    {
        $header_data = explode("\r\n", $raw_head);
        list($this->method, $this->url, $protocol) = explode(' ', $header_data[0]);
        list($null, $this->httpVersion) = explode('/', $protocol);
        unset($header_data[0]);
        foreach($header_data as $content)
        {
            if(empty($content))
            {
                continue;
            }
            $this->rawHeaders[] = $content;
            list($key, $value) = explode(':', $content, 2);
            $this->headers[strtolower($key)] = trim($value);
        }
    }

    public function destroy()
    {
        $this->connection = null;
    }
}


class Response 
{
    public $statusCode = 200;

    public $onDrain = null;

    protected $_statusPhrase = null;

    protected $_connection = null;

    protected $_headers = array();

    public $headersSent = false;
 

    public function __construct($connection)
    {
        $this->_connection = $connection;
        $self = $this;
        $connection->onBufferDrain = function($connection)use($self)
        {
            if($self->onDrain)
            {
               try{
                   call_user_func($self->onDrain, $connection);
               }
               catch (\Exception $e)
               {
                   echo $e;
               }
            }
        };
    }

    protected function initHeader()
    {
        $this->_headers['Connection'] = 'keep-alive';
        $this->_headers['Content-Type'] = 'Content-Type: text/html;charset=utf-8';
    }

    public function writeHead($status_code, $reason_phrase = '', $headers = null)
    {
        if($this->headersSent)
        {
            echo "header has already send\n";
            return false;
        }
        
        $this->statusCode = $status_code;
        if($reason_phrase)
        {
            $this->_statusPhrase = $reason_phrase;
        }
        if($headers)
        {
            foreach($headers as $key=>$val)
            {
                $this->_headers[$key] = $val;
            }
        }
        $head_buffer = $this->getHeadBuffer();
        $this->_connection->send($head_buffer, true);
        $this->headersSent = true;
    }

    public function getHeadBuffer()
    {
        if(!$this->_statusPhrase)
        {
            $this->_statusPhrase = isset(Code::$codes[$this->statusCode]) ? Code::$codes[$this->statusCode] : '';
        }
        $head_buffer = "HTTP/1.1 $this->statusCode $this->_statusPhrase\r\n";
        if(!isset($this->_headers['Content-Length']))
        {
            $head_buffer .= "Transfer-Encoding: chunked\r\n";
        }
        if(!isset($this->_headers['Connection']))
        {
            $head_buffer .= "Connection: keep-alive\r\n";
        }
        foreach($this->_headers as $key=>$val)
        {
            if($key === 'Set-Cookie' && is_array($val))
            {
                foreach($val as $v)
                {
                    $head_buffer .= "Set-Cookie: $v\r\n";
                }
                continue;
            }
            $head_buffer .= "$key: $val\r\n";
        }
        return $head_buffer."\r\n";
    }

    public function setHeader($key, $val)
    {
        $this->_headers[$key] = $val;
    }

    public function getHeader($name)
    {
        return isset($this->_headers[$name]) ? $this->_headers[$name] : '';
    }

    public function removeHeader($name)
    {
        unset($this->_headers[$name]);
    }

    public function write($chunk)
    {
        if(!isset($this->_headers['Content-Length']))
        {
            $chunk = dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n";
        }
        if(!$this->headersSent)
        {
            $head_buffer = $this->getHeadBuffer(); 
            $chunk = $head_buffer . $chunk;
            $this->headersSent = true;
        }
        $this->_connection->send($chunk, true);
    }
   
    public function end($data = null)
    {
        if(!isset($this->_headers['Content-Length']))
        {
            if(null !== $data)
            {
                return $this->_connection->send($data."0\r\n\r\n", true);
            }
            return $this->_connection->send("0\r\n\r\n", true);
        }
        else
        {
            if(null !== $data)
            {
                return $this->_connection->send($data, true);
            }
        }
        return true;
    }
}

class Code
{
     public static $codes = array(
            100 => 'Continue',
            101 => 'Switching Protocols',
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            203 => 'Non-Authoritative Information',
            204 => 'No Content',
            205 => 'Reset Content',
            206 => 'Partial Content',
            300 => 'Multiple Choices',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            305 => 'Use Proxy',
            306 => '(Unused)',
            307 => 'Temporary Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            407 => 'Proxy Authentication Required',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            411 => 'Length Required',
            412 => 'Precondition Failed',
            413 => 'Request Entity Too Large',
            414 => 'Request-URI Too Long',
            415 => 'Unsupported Media Type',
            416 => 'Requested Range Not Satisfiable',
            417 => 'Expectation Failed',
            422 => 'Unprocessable Entity',
            423 => 'Locked',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            505 => 'HTTP Version Not Supported',
      );
}
