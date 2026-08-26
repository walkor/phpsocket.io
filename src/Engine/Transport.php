<?php

namespace PHPSocketIO\Engine;

use PHPSocketIO\Event\Emitter;

abstract class Transport extends Emitter
{
    public string $readyState = 'opening';
    public ?object $req = null;
    public ?object $res = null;
    /** @var callable|null */
    public $shouldClose = null;

    // Not stored: Polling's overlap guard needs $this->req falsy until onRequest().
    // @phpstan-ignore constructor.unusedParameter
    public function __construct(?object $req = null)
    {
    }

    public function noop(): void
    {
    }

    public function onRequest(object $req): void
    {
        $this->req = $req;
    }

    public function close(?callable $fn = null): void
    {
        $this->readyState = 'closing';
        $fn = $fn ?: [$this, 'noop'];
        $this->doClose($fn);
    }

    abstract public function doClose(callable $fn): void;

    public function onError(string $msg, string $desc = ''): void
    {
        if ($this->listeners('error')) {
            $this->emit('error', "TransportError: {$msg}" . ($desc ? " - {$desc}" : ''));
        }
    }

    /**
     * @param array<string, mixed> $packet
     */
    public function onPacket(array $packet): void
    {
        $this->emit('packet', $packet);
    }

    // @phpstan-ignore missingType.return (Polling overrides with an incompatible return on purpose)
    public function onData(string $data)
    {
        $this->onPacket(Parser::decodePacket($data));
    }

    public function onClose(): void
    {
        $this->req = $this->res = null;
        $this->readyState = 'closed';
        $this->emit('close');
        $this->removeAllListeners();
    }

    public function destroy(): void
    {
        $this->req = null;
        $this->res = null;
        $this->readyState = 'closed';
        $this->removeAllListeners();
        $this->shouldClose = null;
    }
}
