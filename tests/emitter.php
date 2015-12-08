<?php
include __DIR__.'/../src/Event/Emitter.php';
ini_set('display_errors', 'on');
$emitter = new PHPSocketIO\Event\Emitter;
$func = function($arg1, $arg2)
{
    var_dump($arg1, $arg2);
};
$emitter->on('removeListener', function($event_name, $func){echo $event_name,':',var_export($func, true),"removed\n";});
$emitter->on('newListener', function($event_name, $func){echo $event_name,':',var_export($func, true)," added\n";});
$emitter->on('test', $func);
$emitter->on('test', $func);
$emitter->emit('test', 1 ,2);
echo "----------------------\n";
$emitter->once('test', $func);
$emitter->emit('test', 3 ,4);
echo "----------------------\n";
$emitter->emit('test', 4 ,4);
echo "----------------------\n";
$emitter->removeListener('test', $func)->emit('test', 5 ,6);
echo "----------------------\n";
$emitter->on('test2', function(){echo "test2\n";});

var_dump($emitter->listeners('test2'));

