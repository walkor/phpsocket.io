<?php

namespace PHPSocketIO\Tests\Unit\Engine\Protocols\Http;

use PHPSocketIO\Engine\Protocols\Http\Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    public function testParseHeadExtractsMethodUrlAndHttpVersion(): void
    {
        $raw = "GET /socket.io/?transport=polling HTTP/1.1\r\nHost: example.com\r\n";

        $request = new Request(new \stdClass(), $raw);

        $this->assertSame('GET', $request->method);
        $this->assertSame('/socket.io/?transport=polling', $request->url);
        $this->assertSame('1.1', $request->httpVersion);
    }

    public function testParseHeadLowercasesHeaderNamesAndTrimsValues(): void
    {
        $raw = "GET / HTTP/1.1\r\nContent-Type:  application/json  \r\nX-Custom: value\r\n";

        $request = new Request(new \stdClass(), $raw);

        $this->assertSame('application/json', $request->headers['content-type']);
        $this->assertSame('value', $request->headers['x-custom']);
    }

    public function testParseHeadSkipsEmptyLines(): void
    {
        $raw = "GET / HTTP/1.1\r\nHost: example.com\r\n\r\n";

        $request = new Request(new \stdClass(), $raw);

        $this->assertCount(1, $request->rawHeaders);
    }

    public function testDestroyClearsCallbacksAndConnection(): void
    {
        $request = new Request(new \stdClass(), "GET / HTTP/1.1\r\n");
        $request->onData = function () {
        };
        $request->onEnd = function () {
        };
        $request->onClose = function () {
        };

        $request->destroy();

        $this->assertNull($request->onData);
        $this->assertNull($request->onEnd);
        $this->assertNull($request->onClose);
        $this->assertNull($request->connection);
    }
}
