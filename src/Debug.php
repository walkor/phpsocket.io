<?php
namespace PHPSocketIO;

class Debug 
{
    public static function debug($var)
    {
        global $debug;
        if($debug)
        echo var_export($var, true)."\n";
    }
}
