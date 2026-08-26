<?php

use Workerman\Worker;

require_once __DIR__ . '/../../../vendor/autoload.php';

new Channel\Server('0.0.0.0', 2206);

Worker::runAll();
