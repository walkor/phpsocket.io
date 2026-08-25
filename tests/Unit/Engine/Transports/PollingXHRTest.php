<?php

namespace PHPSocketIO\Tests\Unit\Engine\Transports;

use PHPSocketIO\Engine\Transports\PollingXHR;
use PHPSocketIO\Tests\Unit\Fixtures\RecordingHttpResponse;
use PHPUnit\Framework\TestCase;
use stdClass;

class PollingXHRTest extends TestCase
{
    private function makeReq(string $method = 'GET', array $headers = []): stdClass
    {
        $req = new stdClass();
        $req->method = $method;
        $req->headers = $headers;
        $req->res = new RecordingHttpResponse();
        return $req;
    }

    public function testOnRequestHandlesOptionsWithCorsHeaders(): void
    {
        $transport = new PollingXHR();
        $req = $this->makeReq('OPTIONS', ['origin' => 'https://example.com']);

        $transport->onRequest($req);

        [[$status, , $headers]] = $req->res->writeHeadCalls;
        $this->assertSame(200, $status);
        $this->assertSame('Content-Type', $headers['Access-Control-Allow-Headers']);
        $this->assertSame('https://example.com', $headers['Access-Control-Allow-Origin']);
        $this->assertCount(1, $req->res->endCalls);
    }

    public function testOnRequestDelegatesGetToParentPollHandling(): void
    {
        $transport = new PollingXHR();
        $req = $this->makeReq('GET');

        $transport->onRequest($req);

        $this->assertSame($req, $transport->req);
    }

    public function testDoWriteUsesPlainTextContentTypeForLengthPrefixedPayload(): void
    {
        $transport = new PollingXHR();
        $transport->req = $this->makeReq();
        $transport->res = new RecordingHttpResponse();

        $transport->doWrite('3:4hi');

        [[, , $headers]] = $transport->res->writeHeadCalls;
        $this->assertSame('text/plain; charset=UTF-8', $headers['Content-Type']);
        $this->assertSame([['3:4hi']], $transport->res->endCalls);
    }

    public function testDoWriteUsesOctetStreamForNonPrefixedPayload(): void
    {
        $transport = new PollingXHR();
        $transport->req = $this->makeReq();
        $transport->res = new RecordingHttpResponse();

        $transport->doWrite('raw-binary');

        [[, , $headers]] = $transport->res->writeHeadCalls;
        $this->assertSame('application/octet-stream', $headers['Content-Type']);
    }

    public function testDoWriteIsNoopWhenResponseMissing(): void
    {
        $transport = new PollingXHR();
        $transport->req = $this->makeReq();
        $transport->res = null;

        $transport->doWrite('3:4hi');

        $this->addToAssertionCount(1); // reaching here means it didn't crash
    }

    public function testHeadersAddsCredentialedCorsWhenOriginPresent(): void
    {
        $transport = new PollingXHR();
        $req = $this->makeReq('GET', ['origin' => 'https://example.com']);

        $headers = $transport->headers($req);

        $this->assertSame('true', $headers['Access-Control-Allow-Credentials']);
        $this->assertSame('https://example.com', $headers['Access-Control-Allow-Origin']);
    }

    public function testHeadersAddsWildcardCorsWhenNoOrigin(): void
    {
        $transport = new PollingXHR();
        $req = $this->makeReq('GET');

        $headers = $transport->headers($req);

        $this->assertSame('*', $headers['Access-Control-Allow-Origin']);
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $headers);
    }

    public function testHeadersInvokesRegisteredHeaderListeners(): void
    {
        $transport = new PollingXHR();
        $req = $this->makeReq('GET');
        $seen = null;
        $transport->on('headers', function ($headers) use (&$seen) {
            $seen = $headers;
        });

        $result = $transport->headers($req, ['X-Test' => '1']);

        $this->assertSame($result, $seen);
    }
}
