<?php

namespace PHPSocketIO\Engine\Transports;

class PollingJsonp extends Polling
{
    public ?string $head = null;
    public string $foot = ');';

    public function __construct(object $req)
    {
        $this->head = '___eio[' . (isset($req->_query['j']) ? preg_replace('/[^0-9]/', '', $req->_query['j']) : '') . '](';
    }

    /**
     * @return void
     */
    public function onData(string $data)
    {
        $parsed_data = null;
        parse_str($data, $parsed_data);
        $data = $parsed_data['d'];
        parent::onData(preg_replace('/\\\\n/', '\\n', $data));
    }

    public function doWrite(string $data): void
    {
        $js = json_encode($data);

        $data = $this->head . $js . $this->foot;

        // explicit UTF-8 is required for pages not served under utf
        $headers = [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'Content-Length' => strlen($data),
            'X-XSS-Protection' => '0'
        ];
        if (empty($this->res)) {
            return;
        }
        $this->res->writeHead(200, '', $this->headers($this->req, $headers));
        $this->res->end($data);
    }

    // $req unused: no CORS needed for JSONP, just matches Polling's shared signature.
    /**
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    public function headers(object $req, array $headers = []): array
    {
        $listeners = $this->listeners('headers');
        foreach ($listeners as $listener) {
            $listener($headers);
        }
        return $headers;
    }
}
