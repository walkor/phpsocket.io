<?php
namespace PHPSocketIO\Engine\Transports;
use \PHPSocketIO\Debug;
class PollingJsonp extends Polling
{
    public $head = null;
    public $foot = ');';

    public function __construct($req)
    {
        $j = isset($req->_query['j']) ? preg_replace('/[^0-9]/', '', $req->_query['j']) : '';
        $this->head = "___eio[ $j ](";
        Debug::debug('PollingJsonp __construct');
    }
public function __destruct()
{
    Debug::debug('PollingJsonp __destruct');
}
    public function onData($data)
    {
        $parsed_data = null;
        parse_str($data, $parsed_data);
        $data = $parsed_data['d'];
        // todo check
        //client will send already escaped newlines as \\\\n and newlines as \\n
        // \\n must be replaced with \n and \\\\n with \\n
        /*data = data.replace(rSlashes, function(match, slashes) {
          return slashes ? match : '\n';
        });*/
        call_user_func(array($this, 'parent::onData'), preg_replace('/\\\\n/', '\\n', $data));
    }

    public function doWrite($data)
    {
        $js = json_encode($data);
        //$js = preg_replace(array('/\u2028/', '/\u2029/'), array('\\u2028', '\\u2029'), $js);

        // prepare response
        $data = $this->head . $js . $this->foot;

        // explicit UTF-8 is required for pages not served under utf
        $headers = array(
            'Content-Type'=> 'text/javascript; charset=UTF-8',
            'Content-Length'=> strlen($data),
            'X-XSS-Protection'=>'0'
        );
        if(empty($this->res)){echo new \Exception('empty $this->res');return;}
        $this->res->writeHead(200, '',$this->headers($this->req, $headers));
        $this->res->end($data);
    }
    
    public function headers($req, $headers = array())
    {
       $listeners = $this->listeners('headers');
       foreach($listeners as $listener)
       {
           $listener($headers);
       }
       return $headers;
    }
 

}
