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
namespace Workerman\Protocols;

use Protocols\Http\Request;
use Protocols\Http\Response;
use Workerman\Connection\TcpConnection;

/**
 * WebSocket 协议服务端解包和打包
 */
class Websocket
{
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
        // flash
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
    protected static function dealHandshake($connection, $req, $res)
    {
        // 握手阶段客户端发送HTTP协议
        if(0 === strpos($buffer, 'GET'))
        {
            // 判断\r\n\r\n边界
            $heder_end_pos = strpos($buffer, "\r\n\r\n");
            if(!$heder_end_pos)
            {
                return 0;
            }
            
            // 解析Sec-WebSocket-Key
            $Sec_WebSocket_Key = '';
            if(preg_match("/Sec-WebSocket-Key: *(.*?)\r\n/i", $buffer, $match))
            {
                $Sec_WebSocket_Key = $match[1];
            }
            else
            {
                $connection->send("HTTP/1.1 400 Bad Request\r\n\r\n<b>400 Bad Request</b><br>Sec-WebSocket-Key not found.<br>This is a WebSocket service and can not be accessed via HTTP.", true);
                $connection->close();
                return 0;
            }
            // 握手的key
            $new_key = base64_encode(sha1($Sec_WebSocket_Key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11",true));
            // 握手返回的数据
            $handshake_message = "HTTP/1.1 101 Switching Protocols\r\n";
            $handshake_message .= "Upgrade: websocket\r\n";
            $handshake_message .= "Sec-WebSocket-Version: 13\r\n";
            $handshake_message .= "Connection: Upgrade\r\n";
            $handshake_message .= "Sec-WebSocket-Accept: " . $new_key . "\r\n\r\n";
            // 标记已经握手
            $connection->websocketHandshake = true;
            // 缓冲fin为0的包，直到fin为1
            $connection->websocketDataBuffer = '';
            // 当前数据帧的长度，可能是fin为0的帧，也可能是fin为1的帧
            $connection->websocketCurrentFrameLength = 0;
            // 当前帧的数据缓冲
            $connection->websocketCurrentFrameBuffer = '';
            // 消费掉握手数据，不触发onMessage
            $connection->consumeRecvBuffer(strlen($buffer));
            // 发送握手数据
            $connection->send($handshake_message, true);
            
            // 握手后有数据要发送
            if(!empty($connection->tmpWebsocketData))
            {
                $connection->send($connection->tmpWebsocketData, true);
                $connection->tmpWebsocketData = '';
            }
            // blob or arraybuffer
            $connection->websocketType = self::BINARY_TYPE_BLOB; 
            // 如果有设置onWebSocketConnect回调，尝试执行
            if(isset($connection->onWebSocketConnect))
            {
                self::parseHttpHeader($buffer);
                try
                {
                    call_user_func($connection->onWebSocketConnect, $connection, $buffer);
                }
                catch(\Exception $e)
                {
                    echo $e;
                }
                $_GET = $_COOKIE = $_SERVER = array();
            }
            return 0;
        }
        // 如果是flash的policy-file-request
        elseif(0 === strpos($buffer,'<polic'))
        {
            $policy_xml = '<?xml version="1.0"?><cross-domain-policy><site-control permitted-cross-domain-policies="all"/><allow-access-from domain="*" to-ports="*"/></cross-domain-policy>'."\0";
            $connection->send($policy_xml, true);
            $connection->consumeRecvBuffer(strlen($buffer));
            return 0;
        }
        // 出错
        $connection->send("HTTP/1.1 400 Bad Request\r\n\r\n<b>400 Bad Request</b><br>Invalid handshake data for websocket. ", true);
        $connection->close();
        return 0;
    }
    
    /**
     * 从header中获取
     * @param string $buffer
     * @return void
     */
    protected static function parseHttpHeader($buffer)
    {
        $header_data = explode("\r\n", $buffer);
        $_SERVER = array();
        list($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['SERVER_PROTOCOL']) = explode(' ', $header_data[0]);
        unset($header_data[0]);
        foreach($header_data as $content)
        {
            // \r\n\r\n
            if(empty($content))
            {
                continue;
            }
            list($key, $value) = explode(':', $content, 2);
            $key = strtolower($key);
            $value = trim($value);
            switch($key)
            {
                // HTTP_HOST
                case 'host':
                    $_SERVER['HTTP_HOST'] = $value;
                    $tmp = explode(':', $value);
                    $_SERVER['SERVER_NAME'] = $tmp[0];
                    if(isset($tmp[1]))
                    {
                        $_SERVER['SERVER_PORT'] = $tmp[1];
                    }
                    break;
                // HTTP_COOKIE
                case 'cookie':
                    $_SERVER['HTTP_COOKIE'] = $value;
                    parse_str(str_replace('; ', '&', $_SERVER['HTTP_COOKIE']), $_COOKIE);
                    break;
                // HTTP_USER_AGENT
                case 'user-agent':
                    $_SERVER['HTTP_USER_AGENT'] = $value;
                    break;
                // HTTP_REFERER
                case 'referer':
                    $_SERVER['HTTP_REFERER'] = $value;
                    break;
                case 'origin':
                    $_SERVER['HTTP_ORIGIN'] = $value;
                    break;
            }
        }
        
        // QUERY_STRING
        $_SERVER['QUERY_STRING'] = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        if($_SERVER['QUERY_STRING'])
        {
            // $GET
            parse_str($_SERVER['QUERY_STRING'], $_GET);
        }
        else
        {
            $_SERVER['QUERY_STRING'] = '';
        }
    }
}
