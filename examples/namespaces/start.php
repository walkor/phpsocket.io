<?php
use Workerman\Worker;

define('GLOBAL_START', true);

require_once __DIR__ . '/start_web.php';
require_once __DIR__ . '/start_io.php';

Worker::runAll();
