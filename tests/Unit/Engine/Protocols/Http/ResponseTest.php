<?php

namespace PHPSocketIO\Tests\Unit\Engine\Protocols\Http;

use PHPSocketIO\Engine\Protocols\Http\Response;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    private function makeFakeConnection(): object
    {
        return new class {
            public array $sendCalls = [];
            public $httpRequest;
            public $httpResponse;

            public function send($data, $raw = false)
            {
                $this->sendCalls[] = [$data, $raw];
                return true;
            }
        };
    }

    public function testGetHeadBufferIncludesStatusLineAndDefaultHeaders(): void
    {
        $response = new Response($this->makeFakeConnection());

        $buffer = $response->getHeadBuffer();

        $this->assertStringContainsString("HTTP/1.1 200 OK\r\n", $buffer);
        $this->assertStringContainsString("Transfer-Encoding: chunked\r\n", $buffer);
        $this->assertStringContainsString("Connection: keep-alive\r\n", $buffer);
    }

    public function testWriteHeadSetsStatusAndCustomHeaders(): void
    {
        $response = new Response($this->makeFakeConnection());

        $response->writeHead(404, '', ['Content-Type' => 'text/plain']);

        $this->assertSame(404, $response->statusCode);
        $this->assertSame('text/plain', $response->getHeader('Content-Type'));
        $this->assertTrue($response->headersSent);
    }

    public function testWriteHeadIsANoopOnceHeadersAlreadySent(): void
    {
        $response = new Response($this->makeFakeConnection());
        $response->writeHead(200);

        $result = $response->writeHead(500);

        $this->assertFalse($result);
        $this->assertSame(200, $response->statusCode);
    }

    public function testSetGetRemoveHeader(): void
    {
        $response = new Response($this->makeFakeConnection());

        $response->setHeader('X-Test', 'value');
        $this->assertSame('value', $response->getHeader('X-Test'));

        $response->removeHeader('X-Test');
        $this->assertSame('', $response->getHeader('X-Test'));
    }

    public function testEndWithoutContentLengthSendsChunkedTerminator(): void
    {
        $conn = $this->makeFakeConnection();
        $response = new Response($conn);

        $response->end('hello');

        $this->assertCount(1, $conn->sendCalls);
        [$data, $raw] = $conn->sendCalls[0];
        $this->assertStringContainsString("0\r\n\r\n", $data);
        $this->assertStringContainsString('hello', $data);
        $this->assertTrue($raw);
    }

    public function testEndOnUnwritableResponseReturnsFalse(): void
    {
        $conn = $this->makeFakeConnection();
        $response = new Response($conn);
        $response->end(); // first end() call marks it not writable via destroy()

        $result = $response->end('too late');

        $this->assertFalse($result);
    }

    public function testDestroyClearsConnectionAndMarksUnwritable(): void
    {
        $conn = $this->makeFakeConnection();
        $response = new Response($conn);

        $response->destroy();

        $this->assertFalse($response->writable);
    }
}
