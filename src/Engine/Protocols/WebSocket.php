<?php 
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace PHPSocketIO\Engine\Protocols;

use \PHPSocketIO\Engine\Protocols\Http\Request;
use \PHPSocketIO\Engine\Protocols\Http\Response;
use \PHPSocketIO\Engine\Protocols\WebSocket\RFC6455;
use \Workerman\Connection\TcpConnection;

/**
 * WebSocket 协议服务端解包和打包
 */
class WebSocket
{
    /**
     * 最小包头
     * @var int
     */
    const MIN_HEAD_LEN = 7;
    
    /**
     * 检查包的完整性
     * @param string $buffer
     */
    public static function input($buffer, $connection)
    {
        if(strlen($buffer) < self::MIN_HEAD_LEN)
        {
            return 0;
        }
        // flash policy file
        if(0 === strpos($buffer,'<policy'))
        {
            $policy_xml = '<?xml version="1.0"?><cross-domain-policy><site-control permitted-cross-domain-policies="all"/><allow-access-from domain="*" to-ports="*"/></cross-domain-policy>'."\0";
            $connection->send($policy_xml, true);
            $connection->consumeRecvBuffer(strlen($buffer));
            return 0;
        }
        // http head
        $pos = strpos($buffer, "\r\n\r\n");
        if(!$pos)
        {
            if(strlen($buffer)>=TcpConnection::$maxPackageSize)
            {
                $connection->close("HTTP/1.1 400 bad request\r\n\r\nheader too long");
                return 0;
            }
            return 0;
        }
        $req = new Request($connection, $buffer);
        $res = new Response($connection);
        $connection->consumeRecvBuffer(strlen($buffer));
        return self::dealHandshake($connection, $req, $res);
        $connection->consumeRecvBuffer($pos+4);
        return 0;
    }

    /**
     * 处理websocket握手
     * @param string $buffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function dealHandshake($connection, $req, $res)
    {
        if(isset($req->headers['sec-websocket-key1']))
        {
            $res->writeHead(400);
            $res->end("Not support");
            return 0;
        }
        $connection->protocol = 'PHPSocketIO\Engine\Protocols\WebSocket\RFC6455';
        return RFC6455::dealHandshake($connection, $req, $res);
    }
}
