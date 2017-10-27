# websocket
这是个简单的例子，这个例子的来源其实是在枕边书同学代码的基础上改写而来的https://github.com/zhenbianshu/websocket

主要对代码进行了事件分离，方便在不同场合使用。

## 文件说明

- WebSocket.php 是websocket封装类
- server.php 功能实现
- index.html 是前端页面

## 功能介绍

本类提供了4个事件
- open 建立socket链接时触发，回调中可以收到websocket对象和socket链接
- message 发送消息时触发，回调中可以收到websocket对象、socket链接、message客户端发来的消息
- error 出现错误时触发，该事件可以方便在服务端做处理，回调中可以收到websocket对象和socket链接
- close 断开链接时触发，回调中可以收到websocket对象和socket链接

2个方法
- connectList 用来获取在线的socket列表
- send 用来发送消息，该方法接受2个参数第一个参数是socket链接 第二个参数是message消息

## 使用方法
可以克隆本项目或下载本项目后
> cd websocket

> path/to/php server.php

然后去浏览器访问index，比如localhost/index.html
