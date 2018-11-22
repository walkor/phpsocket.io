<?php
namespace PHPSocketIO\Engine\Protocols;
use \PHPSocketIO\Engine\Protocols\WebSocket;
use \PHPSocketIO\Engine\Protocols\Http\Request;
use \PHPSocketIO\Engine\Protocols\Http\Response;
use \Workerman\Connection\TcpConnection;
class SocketIO 
{
    public static function input($http_buffer, $connection)
    {
        if(!empty($connection->hasReadedHead))
        {
            return strlen($http_buffer);
        }
        $pos = strpos($http_buffer, "\r\n\r\n"); 
        if(!$pos)
        {
            if(strlen($http_buffer) >= $connection->maxPackageSize)
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
        $connection->hasReadedHead = true;
        TcpConnection::$statistics['total_request']++;
        $connection->onClose = '\PHPSocketIO\Engine\Protocols\SocketIO::emitClose';
        if(isset($req->headers['upgrade']) && strtolower($req->headers['upgrade']) === 'websocket')
        {
            $connection->consumeRecvBuffer(strlen($http_buffer));
            WebSocket::dealHandshake($connection, $req, $res);
            self::cleanup($connection);
            return 0;
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
            if('\PHPSocketIO\Engine\Protocols\SocketIO::onData' !== $connection->onMessage)
            {
                $connection->onMessage = '\PHPSocketIO\Engine\Protocols\SocketIO::onData';
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
    
    public static function onData($connection, $data)
    {
        $req = $connection->httpRequest;
        self::emitData($connection, $req, $data);
        if((isset($req->headers['content-length']) && $req->headers['content-length'] <= strlen($data))     
            || substr($data, -5) === "0\r\n\r\n")
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
    
    public static function emitClose($connection)
    {
        $req = $connection->httpRequest;
        if(isset($req->onClose))
        {
            try
            {
                call_user_func($req->onClose, $req);
            }
            catch(\Exception $e)
            {
                echo $e;
            }
        }
        $res = $connection->httpResponse;
        if(isset($res->onClose))
        {
            try
            {
                call_user_func($res->onClose, $res);
            }
            catch(\Exception $e)
            {
                echo $e;
            }
        }
        self::cleanup($connection);
    }

    public static function cleanup($connection)
    {
        if(!empty($connection->onRequest))
        {
            $connection->onRequest = null;
        }
        if(!empty($connection->onWebSocketConnect))
        {
            $connection->onWebSocketConnect = null;
        }
        if(!empty($connection->httpRequest))
        {
            $connection->httpRequest->destroy();
            $connection->httpRequest = null;
        }
        if(!empty($connection->httpResponse))
        {
            $connection->httpResponse->destroy();
            $connection->httpResponse = null;
        }
    }
    
    public static function emitData($connection, $req, $data)
    {
        if(isset($req->onData))
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
        if(isset($req->onEnd))
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
        $connection->hasReadedHead = false;
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
