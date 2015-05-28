<?php 
use \Workerman\Worker;
use \Workerman\WebServer;
use \Workerman\Autoloader;

// autoload
require_once __DIR__ . '/../../Workerman/Autoloader.php';
Autoloader::setRootPath(__DIR__);
$config=\Config\Config::$config1;
//@see http://doc3.workerman.net/advanced/webserver.html
$web_server = new WebServer("http://".$config['webserver']);
$web_server->name = 'SimpleChatWeb';
$web_server->count = 4;
$web_server->addRoot('example.com', __DIR__.'/Web');

if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}
