<?php
namespace PHPSocketIO\Engine\Protocols\Http;

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
        $this->onData = $this->onEnd = $this->onClose = null;
        $this->connection = null;
    }
}
