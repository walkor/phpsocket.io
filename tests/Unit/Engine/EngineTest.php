<?php

namespace PHPSocketIO\Tests\Unit\Engine;

use PHPSocketIO\Engine\Engine;
use PHPSocketIO\Engine\Socket;
use PHPSocketIO\Tests\Unit\Fixtures\RecordingHttpResponse;
use PHPUnit\Framework\TestCase;
use stdClass;

class EngineTest extends TestCase
{
    private function makeReq(array $query = [], string $method = 'GET', array $headers = []): stdClass
    {
        $req = new stdClass();
        $req->_query = $query;
        $req->method = $method;
        $req->headers = $headers;
        $req->res = new RecordingHttpResponse();
        return $req;
    }

    public function testConstructAppliesRecognizedOptions(): void
    {
        $engine = new Engine(['pingTimeout' => 10, 'pingInterval' => 5, 'unknownOption' => 'ignored']);

        $this->assertSame(10, $engine->pingTimeout);
        $this->assertSame(5, $engine->pingInterval);
        $this->assertFalse(property_exists($engine, 'unknownOption'));
    }

    public function testPrepareParsesQueryStringIntoRequest(): void
    {
        $engine = new Engine();
        $req = new stdClass();
        $req->url = '/socket.io/?transport=polling&sid=abc';

        $ref = new \ReflectionMethod(Engine::class, 'prepare');
        $ref->setAccessible(true);
        $ref->invoke($engine, $req);

        $this->assertSame('polling', $req->_query['transport']);
        $this->assertSame('abc', $req->_query['sid']);
    }

    public function testPrepareDoesNothingWhenQueryAlreadyParsed(): void
    {
        $engine = new Engine();
        $req = new stdClass();
        $req->url = '/socket.io/?transport=polling';
        $req->_query = ['already' => 'set'];

        $ref = new \ReflectionMethod(Engine::class, 'prepare');
        $ref->setAccessible(true);
        $ref->invoke($engine, $req);

        $this->assertSame(['already' => 'set'], $req->_query);
    }

    public function testVerifyRejectsUnknownTransport(): void
    {
        $engine = new Engine();
        $req = $this->makeReq(['transport' => 'carrier-pigeon']);
        $result = null;

        $ref = new \ReflectionMethod(Engine::class, 'verify');
        $ref->setAccessible(true);
        $ref->invoke($engine, $req, $req->res, false, function (...$args) use (&$result) {
            $result = $args;
        });

        [$code, $success] = $result;
        $this->assertSame(0, $code);
        $this->assertFalse($success);
    }

    public function testVerifyRejectsUnknownSid(): void
    {
        $engine = new Engine();
        $req = $this->makeReq(['transport' => 'polling', 'sid' => 'nope']);
        $result = null;

        $ref = new \ReflectionMethod(Engine::class, 'verify');
        $ref->setAccessible(true);
        $ref->invoke($engine, $req, $req->res, false, function (...$args) use (&$result) {
            $result = $args;
        });

        [$code, $success] = $result;
        $this->assertSame(1, $code);
        $this->assertFalse($success);
    }

    public function testVerifyRejectsTransportMismatchForKnownSid(): void
    {
        $engine = new Engine();
        $fakeSocket = new stdClass();
        $fakeSocket->transport = new stdClass();
        $fakeSocket->transport->name = 'polling';
        $engine->clients['sid1'] = $fakeSocket;
        $req = $this->makeReq(['transport' => 'websocket', 'sid' => 'sid1']);
        $result = null;

        $ref = new \ReflectionMethod(Engine::class, 'verify');
        $ref->setAccessible(true);
        $ref->invoke($engine, $req, $req->res, false, function (...$args) use (&$result) {
            $result = $args;
        });

        [$code, $success] = $result;
        $this->assertSame(3, $code);
        $this->assertFalse($success);
    }

    public function testVerifyAllowsUpgradeRequestDespiteTransportNameMismatch(): void
    {
        $engine = new Engine();
        $fakeSocket = new stdClass();
        $fakeSocket->transport = new stdClass();
        $fakeSocket->transport->name = 'polling';
        $engine->clients['sid1'] = $fakeSocket;
        $req = $this->makeReq(['transport' => 'websocket', 'sid' => 'sid1']);
        $result = null;

        $ref = new \ReflectionMethod(Engine::class, 'verify');
        $ref->setAccessible(true);
        $ref->invoke($engine, $req, $req->res, true, function (...$args) use (&$result) {
            $result = $args;
        });

        [$code, $success] = $result;
        $this->assertNull($code);
        $this->assertTrue($success);
    }

    public function testVerifyRejectsNonGetHandshakeRequest(): void
    {
        $engine = new Engine();
        $req = $this->makeReq(['transport' => 'polling'], 'POST');
        $result = null;

        $ref = new \ReflectionMethod(Engine::class, 'verify');
        $ref->setAccessible(true);
        $ref->invoke($engine, $req, $req->res, false, function (...$args) use (&$result) {
            $result = $args;
        });

        [$code, $success] = $result;
        $this->assertSame(2, $code);
        $this->assertFalse($success);
    }

    public function testCheckRequestAllowsAllWhenOriginsWildcard(): void
    {
        $engine = new Engine();
        $req = $this->makeReq([], 'GET', ['origin' => 'https://evil.example']);
        $result = null;

        $engine->checkRequest($req, $req->res, function (...$args) use (&$result) {
            $result = $args;
        });

        [, $success] = $result;
        $this->assertTrue($success);
    }

    public function testCheckRequestAllowsMatchingOrigin(): void
    {
        $engine = new Engine();
        $engine->origins = 'https://good.example';
        $req = $this->makeReq([], 'GET', ['origin' => 'https://good.example']);
        $result = null;

        $engine->checkRequest($req, $req->res, function (...$args) use (&$result) {
            $result = $args;
        });

        [, $success] = $result;
        $this->assertTrue($success);
    }

    public function testCheckRequestRejectsNonMatchingOrigin(): void
    {
        $engine = new Engine();
        $engine->origins = 'https://good.example';
        $req = $this->makeReq([], 'GET', ['origin' => 'https://evil.example']);
        $result = null;

        $engine->checkRequest($req, $req->res, function (...$args) use (&$result) {
            $result = $args;
        });

        [, $success] = $result;
        $this->assertFalse($success);
    }

    public function testCheckRequestAllowsNullOriginForFileUrls(): void
    {
        $engine = new Engine();
        $engine->origins = 'https://good.example';
        $req = $this->makeReq([], 'GET', ['origin' => 'null']);
        $result = null;

        $engine->checkRequest($req, $req->res, function (...$args) use (&$result) {
            $result = $args;
        });

        [, $success] = $result;
        $this->assertTrue($success);
    }

    public function testSendErrorMessageWritesJsonErrorBody(): void
    {
        $engine = new Engine();
        $req = $this->makeReq();

        $ref = new \ReflectionMethod(Engine::class, 'sendErrorMessage');
        $ref->setAccessible(true);
        $ref->invoke($engine, $req, $req->res, 0);

        [[$status]] = $req->res->writeHeadCalls;
        $this->assertSame(403, $status);
        [[$body]] = $req->res->endCalls;
        $decoded = json_decode($body, true);
        $this->assertSame('Transport unknown', $decoded['message']);
    }

    public function testOnSocketCloseRemovesClient(): void
    {
        $engine = new Engine();
        $engine->clients['sid1'] = new stdClass();

        $engine->onSocketClose('sid1');

        $this->assertArrayNotHasKey('sid1', $engine->clients);
    }

    public function testHandshakeCreatesSocketRegistersClientAndEmitsConnection(): void
    {
        $engine = new Engine();
        $req = $this->makeReq(['transport' => 'polling']);
        $connection = new class {
            public function getRemoteIp()
            {
                return '127.0.0.1';
            }

            public function getRemotePort()
            {
                return 4000;
            }
        };
        $req->connection = $connection;

        $connectionSocket = null;
        $engine->on('connection', function ($socket) use (&$connectionSocket) {
            $connectionSocket = $socket;
        });

        $engine->handshake('polling', $req);

        $this->assertCount(1, $engine->clients);
        $this->assertInstanceOf(Socket::class, $connectionSocket);
        $this->assertSame($connectionSocket, reset($engine->clients));
    }

    public function testHandshakeRemovesClientOnSocketClose(): void
    {
        $engine = new Engine();
        $req = $this->makeReq(['transport' => 'polling']);
        $req->connection = new class {
            public function getRemoteIp()
            {
                return '127.0.0.1';
            }

            public function getRemotePort()
            {
                return 4000;
            }
        };

        $engine->handshake('polling', $req);
        $socket = reset($engine->clients);

        $socket->onClose('test');

        $this->assertSame([], $engine->clients);
    }
}
