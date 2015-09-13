<?php
spl_autoload_register(function($name){
    $path = str_replace('\\', DIRECTORY_SEPARATOR ,$name);
    $path = str_replace('PHPSocketIO', '', $path);
    if(is_file($class_file = __DIR__ . "/$path.php"))
    {
        require_once($class_file);
        if(class_exists($name, false))
        {
            return true;
        }
    }
    return false;
});

