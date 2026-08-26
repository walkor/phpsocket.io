<?php

namespace PHPSocketIO\Tests\Unit\Engine\Transports;

use PHPSocketIO\Engine\Transports\PollingJsonp;
use PHPSocketIO\Tests\Unit\Fixtures\RecordingHttpResponse;
use PHPUnit\Framework\TestCase;
use stdClass;

class PollingJsonpTest extends TestCase
{
    private function makeReq(string $j = '3'): stdClass
    {
        $req = new stdClass();
        $req->_query = ['j' => $j];
        return $req;
    }

    public function testConstructBuildsJsonpHeadFromQueryIndex(): void
    {
        $transport = new PollingJsonp($this->makeReq('3'));

        $this->assertSame('___eio[3](', $transport->head);
    }

    public function testConstructStripsNonDigitsFromQueryIndex(): void
    {
        $transport = new PollingJsonp($this->makeReq('3; evil'));

        $this->assertSame('___eio[3](', $transport->head);
    }

    public function testConstructDefaultsToEmptyIndexWhenQueryMissing(): void
    {
        $req = new stdClass();
        $req->_query = [];

        $transport = new PollingJsonp($req);

        $this->assertSame('___eio[](', $transport->head);
    }

    public function testOnDataDecodesFormBodyAndDispatchesPacket(): void
    {
        $transport = new PollingJsonp($this->makeReq());
        $received = null;
        $transport->on('packet', function ($packet) use (&$received) {
            $received = $packet;
        });

        $transport->onData('d=' . urlencode('3:4hi'));

        $this->assertSame(['type' => 'message', 'data' => 'hi'], $received);
    }

    public function testDoWriteWrapsJsonInJsonpCallback(): void
    {
        $transport = new PollingJsonp($this->makeReq('3'));
        $transport->req = $this->makeReq('3');
        $transport->res = new RecordingHttpResponse();

        $transport->doWrite('3:4hi');

        [[$status]] = $transport->res->writeHeadCalls;
        $this->assertSame(200, $status);
        $this->assertSame([['___eio[3]("3:4hi");']], $transport->res->endCalls);
    }

    public function testDoWriteIsNoopWhenResponseMissing(): void
    {
        $transport = new PollingJsonp($this->makeReq());
        $transport->res = null;

        $transport->doWrite('3:4hi');

        $this->addToAssertionCount(1); // reaching here means it didn't crash
    }

    public function testHeadersInvokesRegisteredListeners(): void
    {
        $transport = new PollingJsonp($this->makeReq());
        $seen = null;
        $transport->on('headers', function ($headers) use (&$seen) {
            $seen = $headers;
        });

        $result = $transport->headers($this->makeReq(), ['X-Test' => '1']);

        $this->assertSame($result, $seen);
    }
}
