<?php 
use \Workerman\Worker;
use \Workerman\Autoloader;
use \GatewayWorker\Lib\Db;

// autoload
require_once __DIR__ . '/../../Workerman/Autoloader.php';
Autoloader::setRootPath(__DIR__);

// create Websocket worker
$ws_server = new Worker('Websocket://0.0.0.0:3636');

$ws_server->name = 'SimpleChatWebSocket';

$ws_server->count = 1;

// @see http://doc3.workerman.net/worker-development/on-connect.html
$ws_server->onConnect = function($connection)
{
    // on WebSocket handshake 
    $connection->onWebSocketConnect = function($connection)
    {
        $data = array(
                'type' => 'LGN',
                'time' => date('Y-m-d H:i:s'),
                // @see http://doc3.workerman.net/worker-development/id.html
                'id' => $connection->id,
                'headpic' => '/Public/Image/avatar.jpg',
                'name' => '用户'.$connection->id
        );
        broad_cast(json_encode($data));
    };
};

// @see http://doc3.workerman.net/worker-development/on-message.html
$ws_server->onMessage = function($connection, $content)use($ws_server)
{
    $scontent=json_decode($content,true);
    $time = date('Y-m-d H:i:s');
    $headpic='/Public/Image/avatar.jpg';
    $name='';
    if($scontent&&isset($scontent['bookid'])&&$scontent['chatid']&&$scontent['code']){
        $id=$scontent['chatid'];

        if(verify_code($scontent['chatid'],$scontent['code'])){
            $type='MSG';
            $tcontent=isset($scontent['content'])?$scontent['content']:'';
            if(isset($_SESSION['user_'.$scontent['chatid']])){
                $userinfo=$_SESSION['user_'.$scontent['chatid']];
            }
            else{
                $userinfo=Db::instance('db1')->select('nickname,username,gender,photo')->from('tbl_user')->where('userid='.$scontent['chatid'])->row();
                var_dump($userinfo);

                if($userinfo){
                    $_SESSION['user_'.$scontent['chatid']]=$userinfo;
                }
                else{
                    $type='SYS';
                    $tcontent='账号错误，消息：“'.$scontent['content'].'”，发送失败';
                }

            }
            if($userinfo){
                $headpic=$userinfo['photo']!=''?$userinfo['photo']:'/Public/Image/avatar3.jpg';
                $name=$userinfo['nickname']!=''?$userinfo['nickname']:$userinfo['username'];
            }

        }
        else{
            $type='SYS';
            $tcontent='系统验证失败，消息：“'.$scontent['content'].'”，发送失败';
        }

    }
    else{
        $type='SYS';
        $tcontent='参数错误，消息：“'.$scontent['content'].'”，发送失败';
        $id=-1;

    }
    $data = array(
        'type' => $type,
        'content' => $tcontent,
        'time' => $time,
        // @see http://doc3.workerman.net/worker-development/id.html
        'id' =>$id,
        'headpic' => $headpic,
        'name' => $name
    );
    broad_cast(json_encode($data));
};

// @see http://doc3.workerman.net/worker-development/connection-on-close.html
$ws_server->onClose = function($connection)
{
    $data = array(
                'type' => 'LGT',
                'time' => date('Y-m-d H:i:s'),
                // @see http://doc3.workerman.net/worker-development/id.html
                'id' => $connection->id,
                'headpic' => '/Public/Image/avatar.jpg',
                'name' => '用户'.$connection->id
        );
        broad_cast(json_encode($data));
};

/**
 * broadcast
 * @param string $msg
 * @return void
 */
function broad_cast($msg)
{
    global $ws_server;
    //@see http://doc3.workerman.net/worker-development/connections.html
    foreach($ws_server->connections as $connection)
    {
        // @see http://doc3.workerman.net/worker-development/send.html
        $connection->send($msg);
    }
}

function verify_code($id,$code){
    if(substr(md5('@#'.$id.'*&^'),3,25)==$code){
        return true;
    }
    else{
        return false;
    }
}


// 如果不是在根目录启动，则运行runAll方法
if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}
