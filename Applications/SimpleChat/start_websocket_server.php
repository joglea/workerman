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
        //var_dump($_GET,$_SERVER);
        $userid=isset($_GET['chatid'])?$_GET['chatid']:0;

        $bookid=isset($_GET['bookid'])?$_GET['bookid']:0;


        $db1=Db::instance('db1');
        if($userid){
            if(isset($_SESSION['user_'.$userid])){
                $userinfo=$_SESSION['user_'.$userid];
            }
            else{
                $userinfo=$db1->select('nickname,username,gender,photo')->from('tbl_user')->where('userid= :userid and delflag=0')
                    ->bindValues(array('userid'=>$userid))->row();

            }
        }
        if($userinfo){
            $id=$db1->select('id')->from('tbl_connection')
                ->where('user_id= :userid and book_id= :bookid and delflag=0')->bindValues(array('userid'=>$userid,'bookid'=>$bookid))->single();

            if($id){
                $db1->update('tbl_connection')->cols(array('connectionid'=>$connection->id,'updatetime'=>time()))->where("id=$id and delflag=0")->query();
            }
            else{
                $db1->insert('tbl_connection')->cols(array('connectionid'=>$connection->id,
                                                           'book_id'=>$bookid,
                                                           'user_id'=>$userid,
                                                           'createtime'=>time(),
                                                           'updatetime'=>time(),
                                                           'delflag'=>0
                ))->query();
            }

            $type='LGN';
            $_SESSION['user_'.$userid]=$userinfo;
            $headpic=$userinfo['photo'];
            $name=$userinfo['nickname']!=''?$userinfo['nickname']:$userinfo['username'];
            $tcontent=$name.'连接成功';
        }
        else{
            $userid=0;
            $type='SYS';
            $tcontent='账号错误，连接失败';
            $headpic='';
            $name='';
        }

        $data = array(
                'type' => $type,
                'content' => $tcontent,
                'time' => date('Y-m-d H:i:s'),
                // @see http://doc3.workerman.net/worker-development/id.html
                'id' => $userid,
                'headpic' => $headpic,
                'name' => $name
        );

        $connectionids=$db1->select('connectionid')->from('tbl_connection')
            ->where(' book_id= :bookid and delflag=0')->bindValues(array('bookid'=>$bookid))->column();
        broad_cast(json_encode($data),$connectionids);
    };
};

// @see http://doc3.workerman.net/worker-development/on-message.html
$ws_server->onMessage = function($connection, $content)use($ws_server)
{
    $scontent=json_decode($content,true);
    $userid=$scontent['chatid'];
    $bookid=$scontent['bookid'];
    $time = date('Y-m-d H:i:s');
    $headpic='/Public/Image/avatar.jpg';
    $name='';
    $db1=Db::instance('db1');
    if($scontent&&isset($scontent['bookid'])&&$userid&&$scontent['code']){


        if(verify_code($userid,$scontent['code'])){
            $type='MSG';
            $messagetype=0;
            $tcontent=isset($scontent['content'])?$scontent['content']:'';
            var_dump($_SESSION);
            if(isset($_SESSION['user_'.$userid])){
                $userinfo=$_SESSION['user_'.$userid];
            }
            else{
                $userinfo=$db1->select('nickname,username,gender,photo')->from('tbl_user')->where('userid= :userid and delflag=0')
                    ->bindValues(array('userid'=>$userid))->row();

            }
            if($userinfo){
                $_SESSION['user_'.$userid]=$userinfo;
                $headpic=$userinfo['photo'];
                $name=$userinfo['nickname']!=''?$userinfo['nickname']:$userinfo['username'];

                $bookuserid=$db1->select('user_id')->from('tbl_book')->where('bookid= :bookid and delflag=0')
                    ->bindValues(array('bookid'=>$bookid))->single();
                if($bookuserid==$userid){
                    $messagetype=1;
                }
            }
            else{
                $type='SYS';
                $messagetype=2;
                $tcontent='账号错误，消息：“'.$scontent['content'].'”，发送失败';
            }
        }
        else{
            $type='SYS';
            $messagetype=2;
            $tcontent='系统验证失败，消息：“'.$scontent['content'].'”，发送失败';
        }

    }
    else{
        $type='SYS';
        $messagetype=2;
        $tcontent='参数错误，消息：“'.$scontent['content'].'”，发送失败';
        $userid=-1;

    }

    $db1->insert('tbl_message')->cols(
        array(
            'book_id'=>$bookid,
            'user_id'=>$userid,
            'messagestatus'=>1,
            'messagetype'=>$messagetype,
            'messagecontent'=>$tcontent,
            'isshow'=>1,
            'createtime'=>time(),
            'updatetime'=>time(),
            'delflag'=>0
        )
    )->query();

    $data = array(
        'type' => $type,
        'content' => $tcontent,
        'time' => $time,
        // @see http://doc3.workerman.net/worker-development/id.html
        'id' =>$userid,
        'headpic' => $headpic,
        'name' => $name
    );
    var_dump($data);
    $connectionids=$db1->select('connectionid')->from('tbl_connection')
        ->where(' book_id= :bookid and delflag=0')->bindValues(array('bookid'=>$bookid))->column();
    broad_cast(json_encode($data),$connectionids);
};

// @see http://doc3.workerman.net/worker-development/connection-on-close.html
$ws_server->onClose = function($connection)
{
    $db1=Db::instance('db1');
    $connectioninfo=$db1->select('book_id,user_id')->from('tbl_connection')->where('connectionid= :connectionid and delflag=0')
        ->bindValues(array('connectionid'=>$connection->id))->row();
    //var_dump($connectioninfo);
    $headpic='';
    $name='';
    $tcontent='账号错误，断开连接';
    $type='SYS';
    $connectionids=array($connection->id);
    if($connectioninfo){
        $bookid=$connectioninfo['book_id'];
        $userid=$connectioninfo['user_id'];
        $db1->update('tbl_connection')->cols(array('updatetime'=>time(),'delflag'=>1))->where("connectionid=".$connection->id." and delflag=0")->query();
        $type='LGT';
        $time=date('Y-m-d H:i:s');
        if(isset($_SESSION['user_'.$userid])){
            $userinfo=$_SESSION['user_'.$userid];
        }
        else{
            $userinfo=$db1->select('nickname,username,gender,photo')->from('tbl_user')->where('userid= :userid and delflag=0')
                ->bindValues(array('userid'=>$userid))->row();

        }
        if($userinfo){
            $_SESSION['user_'.$userid]=$userinfo;
            $headpic=$userinfo['photo'];
            $name=$userinfo['nickname']!=''?$userinfo['nickname']:$userinfo['username'];

            $connectionids=$db1->select('connectionid')->from('tbl_connection')
                ->where(' book_id= :bookid and delflag=0')->bindValues(array('bookid'=>$bookid))->column();
        }

    }


    $data = array(
                'type' => $type,
                'time' => $time,
                'content' => $tcontent,
                // @see http://doc3.workerman.net/worker-development/id.html
                'id' => $userid,
                'headpic' => $headpic,
                'name' => $name
        );

    broad_cast(json_encode($data),$connectionids);
};

/**
 * broadcast
 * @param string $msg
 * @return void
 */
function broad_cast($msg,$connectionids=array())
{
    global $ws_server;
    //@see http://doc3.workerman.net/worker-development/connections.html
    foreach($ws_server->connections as $connection)
    {
        if($connectionids&&in_array($connection->id,$connectionids)){
            // @see http://doc3.workerman.net/worker-development/send.html
            $connection->send($msg);
        }

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
