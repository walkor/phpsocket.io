<?php
namespace PHPSocketIO\Engine\Protocols\Http;

class Response
{
    public $statusCode = 200;

    protected $_statusPhrase = null;

    protected $_connection = null;

    protected $_headers = array();

    public $headersSent = false;

    public $writable = true;
    
    protected $_buffer = '';

    public function __construct($connection)
    {
        $this->_connection = $connection;
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
        $this->_buffer = $this->getHeadBuffer();
        $this->headersSent = true;
    }

    public function getHeadBuffer()
    {
        if(!$this->_statusPhrase)
        {
            $this->_statusPhrase = isset(self::$codes[$this->statusCode]) ? self::$codes[$this->statusCode] : '';
        }
        $head_buffer = "HTTP/1.1 $this->statusCode $this->_statusPhrase\r\n";
        if(!isset($this->_headers['Content-Length']) && !isset($this->_headers['Transfer-Encoding']))
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
            $this->_buffer = $head_buffer . $chunk;
            $this->headersSent = true;
        }
        else
        {
            $this->_buffer .= $chunk;
        }
    }
     
    public function end($data = null)
    {
        if(!$this->writable)
        {
            echo new \Exception('unwirtable');
            return false;
        }
        if($data !== null)
        {
            $this->write($data);
        }
        
        if(!$this->headersSent)
        {
            $head_buffer = $this->getHeadBuffer();
            $this->_buffer = $head_buffer;
            $this->headersSent = true;
        }
        
        if(!isset($this->_headers['Content-Length']))
        {
            $ret = $this->_connection->send($this->_buffer . "0\r\n\r\n", true);
            $this->destroy();
            return $ret;
        }
        $ret = $this->_connection->send($this->_buffer, true);
        $this->destroy();
        return $ret;
    }
    
    public function destroy()
    {
        if(!empty($this->_connection->httpRequest))
        {
            $this->_connection->httpRequest->destroy();
        }
        if(!empty($this->_connection))
        {
            $this->_connection->httpResponse = $this->_connection->httpRequest = null;
        }
        $this->_connection = null;
        $this->writable = false;
    }
    
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
