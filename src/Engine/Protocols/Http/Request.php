<?php

namespace PHPSocketIO\Engine\Protocols\Http;

class Request
{
    // $onData/$onEnd/$onClose/$cleanup are intentionally untyped: PHP
    // doesn't allow `callable` as a property type, and these are assigned
    // closures or [$obj, 'method'] callables interchangeably.
    public $onData = null;

    public $onEnd = null;

    public $onClose = null;

    public ?string $httpVersion = null;

    public array $headers = [];

    public ?array $rawHeaders = null;

    public ?string $method = null;

    public ?string $url = null;

    // Duck-typed: real usage is a Workerman TcpConnection, tests use a
    // lightweight double.
    public ?object $connection = null;

    public ?array $_query = null;

    public ?object $res = null;

    public $cleanup = null;

    public function __construct(object $connection, string $raw_head)
    {
        $this->connection = $connection;
        $this->parseHead($raw_head);
    }

    public function parseHead(string $raw_head): void
    {
        $header_data = explode("\r\n", $raw_head);
        [$this->method, $this->url, $protocol] = explode(' ', $header_data[0]);
        [, $this->httpVersion] = explode('/', $protocol);
        unset($header_data[0]);
        foreach ($header_data as $content) {
            if (empty($content)) {
                continue;
            }
            $this->rawHeaders[] = $content;
            [$key, $value] = explode(':', $content, 2);
            $this->headers[strtolower($key)] = trim($value);
        }
    }

    public function destroy(): void
    {
        $this->onData = $this->onEnd = $this->onClose = null;
        $this->connection = null;
    }
}
