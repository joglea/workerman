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


                $type='CON';
                $_SESSION['user_'.$userid]=$userinfo;

                $name=$userinfo['nickname']!=''?$userinfo['nickname']:$userinfo['username'];
                $tcontent=$name.'连接成功';
            }
            else{
                $userid=0;
                $type='SYS';
                $tcontent='账号错误，连接失败';
                $name='';
            }
        }
        else{
            $userid=0;
            $type='SYS';
            $tcontent='作品信息错误，连接失败';
            $name='';
        }



        $data = array(
                'type' => $type,
                'content' => $tcontent,
                'time' => date('Y-m-d H:i:s'),
                // @see http://doc3.workerman.net/worker-development/id.html
                'id' => $userid,
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
    $name='';
    $db1=Db::instance('db1');
    if($scontent&&isset($scontent['bookid'])&&$userid&&$scontent['code']){


        if(verify_code($userid,$scontent['code'])){
            $bookinfo=$db1->select('bookid,user_id')->from('tbl_book')->where('bookid= :bookid and delflag=0')
                ->bindValues(array('bookid'=>$bookid))->row();
            $contype=1;
            if($bookinfo){
                if($bookinfo['user_id']==$userid){
                    $type='DRW';
                    $tcontent=isset($scontent['content'])?$scontent['content']:'';
                    $dtype=$scontent['drawtype'];//为1表示新增   为2表示撤销   为3表示重做
                    if($dtype==1){
                        $maxsort=$db1->select('drawsort')->from('tbl_bookdetail')->where('bookid= :bookid ')
                            ->bindValues(array('bookid'=>$bookid))->orderBy(array('bookdetailid desc'))->single();
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

                        $data=array('drawtype'=>1,
                                    'drawsort'=>$maxsort+1,
                                    'drawcontent'=>$tcontent,);

                    }
                    else if($dtype==2){
                        $bookdetailid=$scontent['bdid'];
                        $db1->update('tbl_bookdetail')->cols(array('updatetime'=>time(),'delflag'=>1))->where('bookdetailid='.$bookdetailid.' and delflag=0')->query();

                        $bddata=$db1->select('bookid,user_id')->from('tbl_book')->where('bookid= :bookid and delflag=0')
                            ->bindValues(array('bookid'=>$bookid))->row();
                        $data=array('drawtype'=>2,
                            'drawsort'=>$bddata['drawsort'],
                            'drawcontent'=>$bddata['drawcontent'],);
                    }
                    else if($dtype==3){
                        $bookdetailid=$scontent['bdid'];
                        $db1->update('tbl_bookdetail')->cols(array('updatetime'=>time(),'delflag'=>0))->where('bookdetailid='.$bookdetailid.' and delflag=1')->query();

                        $bddata=$db1->select('bookid,user_id')->from('tbl_book')->where('bookid= :bookid and delflag=0')
                            ->bindValues(array('bookid'=>$bookid))->row();
                        $data=array('drawtype'=>3,
                                    'drawsort'=>$bddata['drawsort'],
                                    'drawcontent'=>$bddata['drawcontent'],);
                    }

                    $connectionids=$db1->select('connectionid')->from('tbl_connection')
                        ->where(' book_id= :bookid and con_type='.$contype.' and delflag=0 and author=0 ')->bindValues(array('bookid'=>$bookid))->column();
                    $data['type']=$type;
                    broad_cast(json_encode($data),$connectionids);
                }
                else{
                    $type='REDRW';
                    $num=10;//每次操作条数
                    $curdrawsort=$scontent['curdrawsort'];
                    $maxdrawsort=$scontent['maxdrawsort'];
                    $bookdetails=$db1->select('drawsort,drawcontent')->from('tbl_bookdetail')
                        ->where(' drawsort> :cdsort and drawsort< :mdsort and delflag=0')
                        ->bindValues(array('cdsort'=>$curdrawsort,'mdsort'=>$maxdrawsort))->limit($num)->query();


                    $data=array('type'=>$type,
                                'content'=>$bookdetails);
                    $connectionids=array($connection->id);
                }

            }
            else{
                $type='SYS';
                $data=array('type'=>$type,
                            'content'=>'作品不存在');

                $tcontent='作品不存在';
                $connectionids=array($connection->id);
            }


        }
        else{
            $type='SYS';
            $data=array('type'=>$type,
                        'content'=>'系统验证失败');
            $connectionids=array($connection->id);
        }

    }
    else{
        $type='SYS';
        $data=array('type'=>$type,
                    'content'=>'参数错误');
        $connectionids=array($connection->id);

    }


    broad_cast(json_encode($data),$connectionids);
};

// @see http://doc3.workerman.net/worker-development/connection-on-close.html
$ws_server->onClose = function($connection)
{
    $db1=Db::instance('db1');
    $connectioninfo=$db1->select('book_id,user_id')->from('tbl_connection')->where('connectionid= :connectionid and con_type=1 and delflag=0')
        ->bindValues(array('connectionid'=>$connection->id))->row();

    $tcontent='账号错误，断开连接';
    $type='SYS';
    $connectionids=array($connection->id);
    if($connectioninfo){
        $bookid=$connectioninfo['book_id'];
        $userid=$connectioninfo['user_id'];
        $db1->update('tbl_connection')->cols(array('updatetime'=>time(),'delflag'=>1))->where("connectionid=".$connection->id." and con_type=1 and delflag=0")->query();
        $type='DISC';
        $time=date('Y-m-d H:i:s');

        $tcontent='成功断开连接';

    }


    $data = array(
                'type' => $type,
                'time' => $time,
                'content' => $tcontent,
                // @see http://doc3.workerman.net/worker-development/id.html
                'id' => $userid
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
