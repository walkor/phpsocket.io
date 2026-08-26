<?php

namespace PHPSocketIO\Engine\Protocols\Http;

class Response
{
    public int $statusCode = 200;

    protected ?string $_statusPhrase = null;

    // Duck-typed: real usage is a Workerman TcpConnection, tests use a
    // lightweight double.
    protected ?object $_connection = null;

    /** @var array<string, mixed> */
    protected array $_headers = [];

    public bool $headersSent = false;

    public bool $writable = true;

    protected string $_buffer = '';

    public function __construct(object $connection)
    {
        $this->_connection = $connection;
    }

    protected function initHeader(): void
    {
        $this->_headers['Connection'] = 'keep-alive';
        $this->_headers['Content-Type'] = 'Content-Type: text/html;charset=utf-8';
    }

    /**
     * @param array<string, mixed>|null $headers
     * @return bool|null
     */
    public function writeHead(int $status_code, string $reason_phrase = '', ?array $headers = null)
    {
        if ($this->headersSent) {
            return false;
        }
        $this->statusCode = $status_code;
        if ($reason_phrase) {
            $this->_statusPhrase = $reason_phrase;
        }
        if ($headers) {
            foreach ($headers as $key => $val) {
                $this->_headers[$key] = $val;
            }
        }
        $this->_buffer = $this->getHeadBuffer();
        $this->headersSent = true;
        return null;
    }

    public function getHeadBuffer(): string
    {
        if (! $this->_statusPhrase) {
            $this->_statusPhrase = self::$codes[$this->statusCode] ?? '';
        }
        $head_buffer = "HTTP/1.1 $this->statusCode $this->_statusPhrase\r\n";
        if (! isset($this->_headers['Content-Length']) && ! isset($this->_headers['Transfer-Encoding'])) {
            $head_buffer .= "Transfer-Encoding: chunked\r\n";
        }
        if (! isset($this->_headers['Connection'])) {
            $head_buffer .= "Connection: keep-alive\r\n";
        }
        foreach ($this->_headers as $key => $val) {
            if ($key === 'Set-Cookie' && is_array($val)) {
                foreach ($val as $v) {
                    $head_buffer .= "Set-Cookie: $v\r\n";
                }
                continue;
            }
            $head_buffer .= "$key: $val\r\n";
        }
        return $head_buffer . "\r\n";
    }

    /**
     * @param mixed $val
     */
    public function setHeader(string $key, $val): void
    {
        $this->_headers[$key] = $val;
    }

    /**
     * @return mixed
     */
    public function getHeader(string $name)
    {
        return $this->_headers[$name] ?? '';
    }

    public function removeHeader(string $name): void
    {
        unset($this->_headers[$name]);
    }

    public function write(string $chunk): void
    {
        if (! isset($this->_headers['Content-Length'])) {
            $chunk = dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n";
        }
        if (! $this->headersSent) {
            $head_buffer = $this->getHeadBuffer();
            $this->_buffer = $head_buffer . $chunk;
            $this->headersSent = true;
        } else {
            $this->_buffer .= $chunk;
        }
    }

    /**
     * @return mixed
     */
    public function end(?string $data = null)
    {
        if (! $this->writable) {
            return false;
        }
        if ($data !== null) {
            $this->write($data);
        }

        if (! $this->headersSent) {
            $head_buffer = $this->getHeadBuffer();
            $this->_buffer = $head_buffer;
            $this->headersSent = true;
        }

        if (! isset($this->_headers['Content-Length'])) {
            $ret = $this->_connection->send($this->_buffer . "0\r\n\r\n", true);
            $this->destroy();
            return $ret;
        }
        $ret = $this->_connection->send($this->_buffer, true);
        $this->destroy();
        return $ret;
    }

    public function destroy(): void
    {
        if (! empty($this->_connection->httpRequest)) {
            $this->_connection->httpRequest->destroy();
        }
        if (! empty($this->_connection)) {
            $this->_connection->httpResponse = $this->_connection->httpRequest = null;
        }
        $this->_connection = null;
        $this->writable = false;
    }

    /** @var array<int, string> */
    public static array $codes = [
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
    ];
}
