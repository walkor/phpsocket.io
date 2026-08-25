<?php

namespace PHPSocketIO\Tests\Unit\Engine\Transports;

use PHPSocketIO\Engine\Transports\Polling;
use PHPSocketIO\Tests\Unit\Fixtures\RecordingHttpResponse;
use PHPUnit\Framework\TestCase;
use stdClass;

class PollingTest extends TestCase
{
    private function makePolling(): Polling
    {
        return new class extends Polling {
            public array $doWriteCalls = [];

            public function doWrite($data): void
            {
                $this->doWriteCalls[] = $data;
            }

            public function headers(object $req, array $headers = []): array
            {
                return $headers;
            }
        };
    }

    private function makeReq(string $method = 'GET'): stdClass
    {
        $req = new stdClass();
        $req->method = $method;
        $req->res = new RecordingHttpResponse();
        return $req;
    }

    public function testOnRequestRoutesGetToPollRequest(): void
    {
        $polling = $this->makePolling();
        $req = $this->makeReq('GET');

        $polling->onRequest($req);

        $this->assertSame($req, $polling->req);
        $this->assertTrue($polling->writable);
    }

    public function testOnRequestRoutesPostToDataRequest(): void
    {
        $polling = $this->makePolling();
        $req = $this->makeReq('POST');

        $polling->onRequest($req);

        $this->assertSame($req, $polling->dataReq);
    }

    public function testOnRequestRespondsWithErrorForOtherMethods(): void
    {
        $polling = $this->makePolling();
        $req = $this->makeReq('PUT');

        $polling->onRequest($req);

        $this->assertSame([[500]], $req->res->writeHeadCalls);
        $this->assertCount(1, $req->res->endCalls);
    }

    public function testOnPollRequestRejectsOverlappingPoll(): void
    {
        $polling = $this->makePolling();
        $polling->onRequest($this->makeReq('GET'));

        $second = $this->makeReq('GET');
        $errorSeen = null;
        $polling->on('error', function ($msg) use (&$errorSeen) {
            $errorSeen = $msg;
        });
        $polling->onRequest($second);

        $this->assertStringContainsString('Overlap from client', $errorSeen);
        $this->assertSame([[500]], $second->res->writeHeadCalls);
    }

    public function testPollRequestOnCloseClearsReqAndRes(): void
    {
        $polling = $this->makePolling();
        $polling->onRequest($this->makeReq('GET'));

        $polling->pollRequestOnClose();

        $this->assertNull($polling->req);
        $this->assertNull($polling->res);
    }

    public function testDataRequestFullCycleDispatchesPacketAndRespondsOk(): void
    {
        $polling = $this->makePolling();
        $req = $this->makeReq('POST');
        $polling->onRequest($req);

        $received = null;
        $polling->on('packet', function ($packet) use (&$received) {
            $received = $packet;
        });

        $polling->dataRequestOnData($req, '3:4hi');
        $polling->dataRequestOnEnd();

        $this->assertSame(['type' => 'message', 'data' => 'hi'], $received);
        $this->assertSame([['ok']], $req->res->endCalls);
        $this->assertNull($polling->dataReq);
        $this->assertSame('', $polling->chunks);
    }

    public function testOnDataWithClosePacketTypeTriggersOnClose(): void
    {
        $polling = $this->makePolling();
        $polling->writable = false; // avoid the extra noop send() in onClose()
        $closeEmitted = false;
        $polling->on('close', function () use (&$closeEmitted) {
            $closeEmitted = true;
        });

        $polling->onData('1:1');

        $this->assertTrue($closeEmitted);
    }

    public function testSendMarksNotWritableAndEncodesPayload(): void
    {
        $polling = $this->makePolling();
        $polling->writable = true;
        $polling->req = $this->makeReq('GET');

        $polling->send([['type' => 'message', 'data' => 'hi']]);

        $this->assertFalse($polling->writable);
        $this->assertSame(['3:4hi'], $polling->doWriteCalls);
    }

    public function testSendAppendsClosePacketAndInvokesShouldClose(): void
    {
        $polling = $this->makePolling();
        $polling->req = $this->makeReq('GET');
        $shouldCloseCalled = false;
        $polling->shouldClose = function () use (&$shouldCloseCalled) {
            $shouldCloseCalled = true;
        };

        $polling->send([['type' => 'message', 'data' => 'hi']]);

        $this->assertTrue($shouldCloseCalled);
        $this->assertNull($polling->shouldClose);
        $this->assertSame(['3:4hi1:1'], $polling->doWriteCalls);
    }

    public function testWriteInvokesReqCleanupCallback(): void
    {
        $polling = $this->makePolling();
        $polling->req = $this->makeReq('GET');
        $cleanupCalled = false;
        $polling->req->cleanup = function () use (&$cleanupCalled) {
            $cleanupCalled = true;
        };

        $polling->write('payload');

        $this->assertTrue($cleanupCalled);
        $this->assertSame(['payload'], $polling->doWriteCalls);
    }

    public function testDoCloseSendsClosePacketImmediatelyWhenWritable(): void
    {
        $polling = $this->makePolling();
        $polling->writable = true;
        $polling->req = $this->makeReq('GET');
        $fnCalled = false;

        $polling->doClose(function () use (&$fnCalled) {
            $fnCalled = true;
        });

        $this->assertTrue($fnCalled);
        $this->assertSame(['1:1'], $polling->doWriteCalls);
    }

    public function testDoCloseDefersViaShouldCloseWhenNotWritable(): void
    {
        $polling = $this->makePolling();
        $polling->writable = false;
        $fnCalled = false;
        $fn = function () use (&$fnCalled) {
            $fnCalled = true;
        };

        $polling->doClose($fn);

        $this->assertFalse($fnCalled);
        $this->assertSame($fn, $polling->shouldClose);
    }
}
