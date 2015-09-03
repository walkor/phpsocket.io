<?php
namespace Engine\Protocols;
use Protocols\Http\Request;
use Protocols\Http\Response;
use Workerman\Connection\TcpConnection;
class Http
{
    public static function input($http_buffer, $connection)
    {
        if(!empty($connection->httpRequest))
        {
            return strlen($http_buffer);
        }
        $pos = strpos($http_buffer, "\r\n\r\n"); 
        if(!$pos)
        {
            if(strlen($http_buffer)>=TcpConnection::$maxPackageSize)
            {
                $connection->close("HTTP/1.1 400 bad request\r\n\r\nheader too long");
                return 0;
            }
            return 0;
        }
        $head_len = $pos + 4;
        $raw_head = substr($http_buffer, 0, $head_len);
        $raw_body = substr($http_buffer, $head_len);
        $req = new Request($connection, $raw_head);
        $res = new Response($connection);
        $connection->httpRequest = $req;
        $connection->httpResponse = $res;
        if(isset($req->headers['upgrade']) && $req->headers['upgrade'] = 'websocket')
        {
            self::upgradeToWebSocket($connection, $req, $res);
        }
        if(!empty($connection->onRequest))
        {
            $connection->consumeRecvBuffer(strlen($http_buffer));
            self::emitRequest($connection, $req, $res);
            
            if($req->method === 'GET' || $req->method === 'OPTIONS')
            {
                self::emitEnd($connection, $req);
                return 0;
            }

            // POST
            if('\Protocols\Http2::onData' !== $connection->onMessage)
            {
                $connection->onMessage = '\Protocols\Http2::onData';
            }
            if(!$raw_body)
            {
                return 0;
            }
            self::onData($connection, $raw_body);
            return 0;
        }
        else
        {
            if($req->method === 'GET')
            {
                return $pos + 4;
            }
            elseif(isset($req->headers['content-length']))
            {
                return $req->headers['content-length'];
            }
            else
            {
                $connection->close("HTTP/1.1 400 bad request\r\n\r\ntrunk not support");
                return 0; 
            }
        }
    }
    
    public static function upgradeToWebSocket($connection, $req, $res)
    {
        $headers = array();
        if(isset($connection->onWebSocketConnect))
        {
            call_user_func_array($connection->onWebSocketConnect, array($connection, $req, &$headers));
        }
        
        if(isset($req->headers['sec-websocket-key']))
        {
            $sec_websocket_key = $req->headers['sec-websocket-key'];
        }
        else
        {
            $res->writeHead(400);
            $res->end('<b>400 Bad Request</b><br>Upgrade to websocket but Sec-WebSocket-Key not found.');
            return 0;
        }
        
        $sec_websocket_accept = base64_encode(sha1($sec_websocket_key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true));
        $headers['Upgrade'] = 'websocket';
        $headers['Sec-WebSocket-Version'] = 13;
        $headers['Connection'] = 'Upgrade';
        $headers['Sec-WebSocket-Accept'] = $sec_websocket_accept;
        
    }
    
    public static function onData($connection, $data)
    {
        $req = $connection->httpRequest;
        self::emitData($connection, $req, $data);
        if((isset($req->headers['content-length']) && $req->headers['content-length'] <= strlen($data))     
            || substr($data, -5) = "0\r\n\r\n")
        {
            self::emitEnd($connection, $req);
        }
    }

    protected static function emitRequest($connection, $req, $res)
    {
        try
        {
            call_user_func($connection->onRequest, $req, $res);
        }
        catch(\Exception $e)
        {
            echo $e;
        }
    }
    
    public static function emitClose($connection, $req)
    {
        if(!$req->onClose)
        {
            return;
        }
        try
        {
            call_user_func($req->onClose, $req);
        }
        catch(\Exception $e)
        {
            echo $e;
        }
    }
    
    public static function emitData($connection, $req, $data)
    {
        if($req->onData)
        {
            try
            {
                call_user_func($req->onData, $req, $data);
            }
            catch(\Exception $e)
            {
                echo $e;
            }
        }
    } 
    
    public static function emitEnd($connection, $req)
    {
        if($req->onEnd)
        {
            try
            {
                call_user_func($req->onEnd, $req);
            }
            catch(\Exception $e)
            {
                echo $e;
            }
        }
        $connection->httpRequest = $connection->httpResponse = null;
    }

    public static function encode($buffer, $connection)
    {
        if(!isset($connection->onRequest))
        {
            $connection->httpResponse->setHeader('Content-Length', strlen($buffer));
            return $connection->httpResponse->getHeadBuffer() . $buffer;
        }
        return $buffer;
    }

    public static function decode($http_buffer, $connection)
    {
        if(isset($connection->onRequest))
        {
            return $http_buffer;
        }
        else
        {
            list($head, $body) = explode("\r\n\r\n", $http_buffer, 2);
            return $body;
        }
    }
}

