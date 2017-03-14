<?php
namespace PHPSocketIO;
class ChannelAdapter extends DefaultAdapter
{
    protected $_channelId = null;
    
    public static $ip = '127.0.0.1';
    
    public static $port = 2206;
    
    public function __construct($nsp)
    {
        parent::__construct($nsp);
        $this->_channelId = (function_exists('random_int') ? random_int(1, 10000000): rand(1, 10000000)) . "-" . (function_exists('posix_getpid') ? posix_getpid(): 1);
        \Channel\Client::connect(self::$ip, self::$port);
        \Channel\Client::$onMessage = array($this, 'onChannelMessage');
        \Channel\Client::subscribe("socket.io#/#");
        Debug::debug('ChannelAdapter __construct');
    }
    
    public function __destruct()
    {
        Debug::debug('ChannelAdapter __destruct');
    }
    
    public function add($id ,$room)
    {
        $this->sids[$id][$room] = true;
        $this->rooms[$room][$id] = true;
        $channel = "socket.io#/#$room#";
        \Channel\Client::subscribe($channel);
    }
    
    public function del($id, $room)
    {
        unset($this->sids[$id][$room]);
        unset($this->rooms[$room][$id]);
        if(empty($this->rooms[$room]))
        {
            unset($this->rooms[$room]);
            $channel = "socket.io#/#$room#";
            \Channel\Client::unsubscribe($channel);
        }
    }
    
    public function delAll($id)
    {
        $rooms = isset($this->sids[$id]) ? $this->sids[$id] : array();
        if($rooms)
        {
            foreach($rooms as $room)
            {
                if(isset($this->rooms[$room][$id]))
                {
                    unset($this->rooms[$room][$id]);
                    $channel = "socket.io#/#$room#";
                    \Channel\Client::unsubscribe($channel);
                }
            }
        }
        if(empty($this->rooms[$room]))
        {
            unset($this->rooms[$room]);
        }
        unset($this->sids[$id]);
    }

    public function onChannelMessage($channel, $msg)
    {
        if($this->_channelId === array_shift($msg))
        {
            //echo "ignore same channel_id \n";
            return;
        }
        
        $packet = $msg[0];
        
        $opts = $msg[1];
        
        if(!$packet)
        {
            echo "invalid  channel:$channel packet \n";
            return;
        }
        
        if(empty($packet['nsp'])) 
        {
            $packet['nsp'] = '/';
        }
        
        if($packet['nsp'] != $this->nsp->name) 
        {
             echo "ignore different namespace {$packet['nsp']} != {$this->nsp->name}\n";
             return;
        }
        
        $this->broadcast($packet, $opts, true);
    }
    
    public function broadcast($packet, $opts, $remote = false)
    {
        parent::broadcast($packet, $opts);
        if (!$remote) 
        {
            $packet['nsp'] = '/';
            
            if(!empty($opts['rooms'])) 
            {
              foreach($opts['rooms'] as $room)
              {
                  $chn = "socket.io#/#$room#";
                  $msg = array($this->_channelId, $packet, $opts);
                  \Channel\Client::publish($chn, $msg);
              }
            }
            else
            {
              $chn = "socket.io#/#";
              $msg = array($this->_channelId, $packet, $opts);
              \Channel\Client::publish($chn, $msg);
            }
        } 
    }
}
