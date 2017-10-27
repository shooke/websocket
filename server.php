<?php
error_reporting(E_ALL);
set_time_limit(0);// 设置超时时间为无限,防止超时
date_default_timezone_set('Asia/shanghai');
require 'WebSocket.php';

$list =  [];
$webSocket = new WebSocket("127.0.0.1", "8080");
$webSocket->on('open',function($ws , $socket){
    // 向客户端发送握手成功消息,以触发客户端发送用户名动作;
    $msg = [
        'type' => 'handshake',
        'content' => 'done',
    ];
    $msg = json_encode($msg);
    $ws->send($socket,$msg);
});
$webSocket->on('message',function($ws , $socket, $message){
    echo $message;
    global $list;
    $msg = json_decode($message,true);
    if($msg['type'] == 'login'){
        $list[(int)$socket]['uname'] = $msg['content'];
        // 取得最新的名字记录
        $user_list = array_column($list, 'uname');
        $response['type'] = 'login';
        $response['content'] = $msg['content'];
        $response['user_list'] = $user_list;
    }
    if($msg['type'] == 'user'){
        $uname = $list[(int)$socket]['uname'];
        $response['type'] = 'user';
        $response['from'] = $uname;
        $response['content'] = $msg['content'];
    }
    // 所有会员
    $sockets = $ws->connectList();
    var_dump($sockets);
    foreach ($sockets as $s){
        $ws->send($s,json_encode($response));
    }
});
$webSocket->on('error',function($ws , $socket){
    echo "error";
    // 向客户端发送握手成功消息,以触发客户端发送用户名动作;
    $msg = [
        'type' => 'error',
        'content' => 'done',
    ];
    $msg = json_encode($msg);
    $ws->send($socket,$msg);
});
$webSocket->on('close',function($ws , $socket){
    global $list;
    // 向客户端发送握手成功消息,以触发客户端发送用户名动作;
    $msg = [
        'type' => 'logout',
        'content' => $list[(int)$socket]['uname'],
    ];
    unset($list[(int)$socket]);
    $user_list = array_column($list, 'uname');
    $msg['user_list'] = $user_list;

    $sockets = $ws->connectList();

    foreach ($sockets as $s){
//        var_dump($s);
        $ws->send($s,json_encode($msg));
    }
});
$webSocket->start();
