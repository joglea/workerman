<?php 
use \Workerman\Worker;
use \Workerman\Autoloader;
use \GatewayWorker\Lib\Db;

// autoload
require_once __DIR__ . '/../../Workerman/Autoloader.php';
Autoloader::setRootPath(__DIR__);

// create Websocket worker
$ws_server = new Worker('Websocket://0.0.0.0:3637');

$ws_server->name = 'SimpleChatWebSocket';

$ws_server->count = 1;



// @see http://doc3.workerman.net/worker-development/on-connect.html
$ws_server->onConnect = function($connection)
{
    // on WebSocket handshake 
    $connection->onWebSocketConnect = function($connection)
    {
        //var_dump($_GET,$_SERVER);
        $userid=isset($_GET['userid'])?$_GET['userid']:0;

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

        $bookinfo=$db1->select('bookid,user_id')->from('tbl_book')->where('bookid= :bookid and delflag=0')
            ->bindValues(array('bookid'=>$bookid))->row();

        if($bookinfo){
            if($userinfo){
                $contype=1;
                if($userid==$bookinfo['user_id']){
                    $author=1;
                }
                else{
                    $author=0;
                }
                $id=$db1->select('id')->from('tbl_connection')
                    ->where('user_id= :userid and book_id= :bookid and con_type='.$contype.' and delflag=0')->bindValues(array('userid'=>$userid,'bookid'=>$bookid))->single();

                if($id){
                    $db1->update('tbl_connection')->cols(array('connectionid'=>$connection->id,'updatetime'=>time()))->where('id='.$id.' and con_type='.$contype.' and delflag=0')->query();
                }
                else{
                    $db1->insert('tbl_connection')->cols(array('connectionid'=>$connection->id,
                                                               'con_type'=>$contype,
                                                               'author'=>$author,
                                                               'book_id'=>$bookid,
                                                               'user_id'=>$userid,
                                                               'createtime'=>time(),
                                                               'updatetime'=>time(),
                                                               'delflag'=>0
                    ))->query();
                }


                $cmd='CON';
                $_SESSION['user_'.$userid]=$userinfo;

                $name=$userinfo['nickname']!=''?$userinfo['nickname']:$userinfo['username'];
                $tcontent=$name.'连接成功';
            }
            else{
                $userid=0;
                $cmd='SYS';
                $tcontent='账号错误，连接失败';
                $name='';
            }
        }
        else{
            $userid=0;
            $cmd='SYS';
            $tcontent='作品信息错误，连接失败';
            $name='';
        }



        $data = array(
                'cmd' => $cmd,
                'content' => $tcontent,
                'time' => date('Y-m-d H:i:s'),
                // @see http://doc3.workerman.net/worker-development/id.html
                'id' => $userid,
                'name' => $name
        );

        $connectionids=$db1->select('connectionid')->from('tbl_connection')
            ->where(' book_id= :bookid and delflag=0')->bindValues(array('bookid'=>$bookid))->column();
        draw_broad_cast(json_encode($data),$connectionids);
    };
};

// @see http://doc3.workerman.net/worker-development/on-message.html
$ws_server->onMessage = function($connection, $content)use($ws_server)
{
    $scontent=json_decode($content,true);
    $userid=$scontent['userid'];
    $bookid=$scontent['bookid'];
    $cmd=$scontent['cmd'];
    $time = date('Y-m-d H:i:s');
    $name='';
    $db1=Db::instance('db1');
    if($scontent&&isset($scontent['bookid'])&&$userid&&$scontent['code']){


        if(draw_verify_code($bookid.$userid,$scontent['code'])){
            $bookinfo=$db1->select('bookid,user_id')->from('tbl_book')->where('bookid= :bookid and delflag=0')
                ->bindValues(array('bookid'=>$bookid))->row();
            $contype=1;//连接类型  1创作  2聊天
            if($bookinfo){
                if($bookinfo['user_id']==$userid){
                    //刚打开的时候进行加载作品之前的内容
                    if($cmd=='PRELOAD'){
                        $bookdetaillist=$db1->select('bookdetailid,drawcontent')->from('tbl_bookdetail')->where('bookid= :bookid and delflag=0 ')
                            ->bindValues(array('bookid'=>$bookid))->orderBy(array('drawsort asc'))->query();
                        $newbookdetaillist=array();
                        foreach($bookdetaillist as $bookdetail){
                            $newbookdetaillist[$bookdetail['bookdetailid']]=$bookdetail['drawcontent'];
                        }
                        $data=array(
                            'cmd'=>'PRELOAD',
                            'drawcontent'=>$newbookdetaillist
                        );
                        $connectionids=array($connection->id);


                    }
                    else if($cmd=='DRAW'){//表示新增

                        $tcontent=isset($scontent['drawcontent'])?$scontent['drawcontent']:'';
                        $dtype=$scontent['drawtype'];//为1   为2表示撤销   为3表示重做

                        $maxsort=$db1->select('drawsort')->from('tbl_bookdetail')->where('bookid= :bookid ')
                            ->bindValues(array('bookid'=>$bookid))->orderBy(array('drawsort desc'))->single();
                        $bookdetailid=$db1->insert('tbl_bookdetail')->cols(
                            array(
                                'book_id'=>$bookid,
                                'drawsort'=>$maxsort+1,
                                'drawcontent'=>$tcontent,
                                'drawtime'=>time(),
                                'updatetime'=>time(),
                                'delflag'=>0
                            )
                        )->query();

                        $data=array(
                            'cmd'=>'DRAW',
                            'bookdetailid'=>$bookdetailid,
                            'drawcontent'=>$tcontent,
                        );

                        $connectionids=$db1->select('connectionid')->from('tbl_connection')
                            ->where(' book_id= :bookid  and con_type='.$contype.' and delflag=0 and author=0 ')
                            ->bindValues(array('bookid'=>$bookid))->column();




                    }
                    else if($cmd=='UNDRAW'){ //表示撤销
                        $bookdetailid=$scontent['bdid'];
                        $db1->update('tbl_bookdetail')->cols(array('updatetime'=>time(),'delflag'=>1))->where('bookdetailid='.$bookdetailid.' and delflag=0')->query();

                        $data=array(
                            'cmd'=>'UNDRAW',
                            'bookdetailid'=>$bookdetailid
                        );
                        $connectionids=$db1->select('connectionid')->from('tbl_connection')
                            ->where(' book_id= :bookid  and con_type='.$contype.' and delflag=0 and author=0 ')
                            ->bindValues(array('bookid'=>$bookid))->column();
                    }
                    else if($cmd=='REDRAW'){ //表示重做
                        $bookdetailid=$scontent['bdid'];
                        $db1->update('tbl_bookdetail')->cols(array('updatetime'=>time(),'delflag'=>0))->where('bookdetailid='.$bookdetailid.' and delflag=1')->query();

                        $data=array(
                            'cmd'=>'REDRAW',
                            'bookdetailid'=>$bookdetailid
                        );
                        $connectionids=$db1->select('connectionid')->from('tbl_connection')
                            ->where(' book_id= :bookid  and con_type='.$contype.' and delflag=0 and author=0 ')
                            ->bindValues(array('bookid'=>$bookid))->column();
                    }
                }
                else{
                    if($cmd=='PRELOAD'){
                        $bookdetaillist=$db1->select('bookdetailid,drawcontent')->from('tbl_bookdetail')->where('bookid= :bookid and delflag=0 ')
                            ->bindValues(array('bookid'=>$bookid))->orderBy(array('drawsort asc'))->query();
                        $newbookdetaillist=array();
                        foreach($bookdetaillist as $bookdetail){
                            $newbookdetaillist[$bookdetail['bookdetailid']]=$bookdetail['drawcontent'];
                        }
                        $data=array(
                            'cmd'=>'PRELOAD',
                            'drawcontent'=>$newbookdetaillist
                        );
                        $connectionids=array($connection->id);
                    }
                }
            }
            else{
                $cmd='SYS';
                $data=array('cmd'=>$cmd,
                            'content'=>'作品不存在');

                $tcontent='作品不存在';
                $connectionids=array($connection->id);
            }
        }
        else{
            $cmd='SYS';
            $data=array('cmd'=>$cmd,
                        'content'=>'系统验证失败');
            $connectionids=array($connection->id);
        }
    }
    else{
        $cmd='SYS';
        $data=array('cmd'=>$cmd,
                    'content'=>'参数错误');
        $connectionids=array($connection->id);
    }

    draw_broad_cast(json_encode($data),$connectionids);
};

// @see http://doc3.workerman.net/worker-development/connection-on-close.html
$ws_server->onClose = function($connection)
{
    $db1=Db::instance('db1');
    $connectioninfo=$db1->select('book_id,user_id')->from('tbl_connection')->where('connectionid= :connectionid and con_cmd=1 and delflag=0')
        ->bindValues(array('connectionid'=>$connection->id))->row();

    $tcontent='账号错误，断开连接';
    $cmd='SYS';
    $connectionids=array($connection->id);
    if($connectioninfo){
        $bookid=$connectioninfo['book_id'];
        $userid=$connectioninfo['user_id'];
        $db1->update('tbl_connection')->cols(array('updatetime'=>time(),'delflag'=>1))
            ->where("connectionid=".$connection->id." and and book_id=".$bookid." and con_cmd=1 and delflag=0")->query();
        $cmd='DISC';
        $time=date('Y-m-d H:i:s');

        $tcontent='成功断开连接';

    }


    $data = array(
                'cmd' => $cmd,
                'time' => $time,
                'content' => $tcontent,
                // @see http://doc3.workerman.net/worker-development/id.html
                'id' => $userid
        );

    draw_broad_cast(json_encode($data),$connectionids);
};

/**
 * broadcast
 * @param string $msg
 * @return void
 */
function draw_broad_cast($msg,$connectionids=array())
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

function draw_verify_code($id,$code){
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
