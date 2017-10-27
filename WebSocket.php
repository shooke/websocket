<?php

/**
 * Class WebSocket
 *
 * 该类办函4个回调时间分别是
 * open，message，error，close，可以用on方法绑定
 * 提供2个公用方法
 * connectList获取在线用户列表
 * send发送消息给指定用户，该方法接受2个参数
 *
 * 使用方式下面有例子
 * $webSocket = new WebSocket("127.0.0.1", "8080");
 * $webSocket->on('open',function($ws , $socket){
 * $ws->connectList();
 * $ws->send($socket,'open');
 * });
 * $webSocket->on('message',function($ws , $socket, $message){
 * $ws->connectList();
 * $ws->send($socket,$message);
 * });
 * $webSocket->on('error',function($ws , $socket){});
 * $webSocket->on('close',function($ws , $socket){});
 * $webSocket->start();
 * //获取所有在线用户，注意本方法要在open后才可调用，最好实在open 和message的回调中使用
 * $webSocket->connectList();
 * //传递接收消息的socket和消息即可
 * $webSocket->send($socket,$msg);
 */
class WebSocket
{
    const LOG_PATH = '/tmp/';
    const LISTEN_SOCKET_NUM = 9;

    /**
     * @var array $sockets
     *    [
     *      (int)$socket => [
     *                        info
     *                      ]
     *      ]
     *  todo 解释socket与file号对应
     */
    private $sockets = [];
    private $master;

    private $host;
    private $port;

    //事件名称
    const EVENT_OPEN = 'OPEN';
    const EVENT_MESSAGE = 'MESSAGE';
    const EVENT_CLOSE = 'CLOSE';
    const EVENT_ERROR = 'ERROR';
    /**
     * 事件对象
     * @var callable
     */
    private $onOpen;
    private $onMessage;
    private $onClose;
    private $onError;

    public function __construct($host, $port)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function start()
    {
        try {
            $this->createSocket();//创建socket
        } catch (\Exception $e) {
            $err_code = socket_last_error();
            $err_msg = socket_strerror($err_code);

            $this->error([
                'error_init_server',
                $err_code,
                $err_msg
            ]);
        }

        $this->sockets[0] = ['resource' => $this->master];
        $pid = posix_getpid();
        $this->debug(["server: {$this->master} started,pid: {$pid}"]);

        while (true) {
            try {
                $this->doServer();
            } catch (\Exception $e) {
                $this->error([
                    'error_do_server',
                    $e->getCode(),
                    $e->getMessage()
                ]);
            }
        }
    }

    /**
     * 所有链接
     * @return array
     */
    public function connectList()
    {
        $sockets = [];
        foreach ($this->sockets as $key => $socket) {
            if (isset($socket['handshake'])) {
                $sockets[$key] = $socket['resource'];
            }
        }
        return $sockets;
    }

    /**
     * 事件绑定
     * @param $event
     * @param $callable
     */
    public function on($event, $callable)
    {
        switch (strtoupper($event)) {
            case self::EVENT_OPEN:
                $this->onOpen = $callable;
                break;
            case self::EVENT_MESSAGE:
                $this->onMessage = $callable;
                break;
            case self::EVENT_CLOSE:
                $this->onClose = $callable;
                break;
            case self::EVENT_ERROR:
                $this->onError = $callable;
        }
    }

    /**
     * 创建socket
     */
    private function createSocket()
    {
        $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        // 设置IP和端口重用,在重启服务器后能重新使用此端口;
        socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1);
        // 将IP和端口绑定在服务器socket上;
        socket_bind($this->master, $this->host, $this->port);
        // listen函数使用主动连接套接口变为被连接套接口，使得一个进程可以接受其它进程的请求，从而成为一个服务器进程。在TCP服务器编程中listen函数把进程变为一个服务器，并指定相应的套接字变为被动连接,其中的能存储的请求不明的socket数目。
        socket_listen($this->master, self::LISTEN_SOCKET_NUM);
    }

    private function doServer()
    {
        $write = $except = null;
        $sockets = array_column($this->sockets, 'resource');
        $read_num = socket_select($sockets, $write, $except, null);
        // select作为监视函数,参数分别是(监视可读,可写,异常,超时时间),返回可操作数目,出错时返回false;
        if (false === $read_num) {
            $errCode = socket_last_error();
            $this->error([
                'error_select',
                $errCode,
                socket_strerror($errCode)
            ]);
            return;
        }

        foreach ($sockets as $socket) {
            // 如果可读的是服务器socket,则处理连接逻辑
            if ($socket == $this->master) {
                $client = socket_accept($this->master);
                // 创建,绑定,监听后accept函数将会接受socket要来的连接,一旦有一个连接成功,将会返回一个新的socket资源用以交互,如果是一个多个连接的队列,只会处理第一个,如果没有连接的话,进程将会被阻塞,直到连接上.如果用set_socket_blocking或socket_set_noblock()设置了阻塞,会返回false;返回资源后,将会持续等待连接。
                if (false === $client) {
                    $errCode = socket_last_error();
                    $this->error([
                        'err_accept',
                        $errCode,
                        socket_strerror($errCode)
                    ]);
                    continue;
                } else {
                    $this->connect($client);
                    continue;
                }
            } else {
                // 如果可读的是其他已连接socket,则读取其数据,并处理应答逻辑
                $bytes = @socket_recv($socket, $buffer, 2048, 0);
                if ($bytes < 9) {
                    // 断开链接
                    $this->disconnect($socket);
                    continue;
                }

                if (!$this->sockets[(int)$socket]['handshake']) {
                    // 握手
                    $this->handShake($socket, $buffer);
                    continue;
                } else {
                    // 消息通讯
                    $recvMsg = $this->parse($buffer);
                    call_user_func_array($this->onMessage, [$this, $socket, $recvMsg]);

                }


            }
        }
    }

    /**
     * 将socket添加到已连接列表,但握手状态留空;
     *
     * @param $socket
     */
    public function connect($socket)
    {
        socket_getpeername($socket, $ip, $port);
        $socketInfo = [
            'resource' => $socket,
            'handshake' => false,
            'ip' => $ip,
            'port' => $port,
        ];
        $this->sockets[(int)$socket] = $socketInfo;
        $this->debug(array_merge(['socket_connect'], $socketInfo));
    }

    /**
     * 客户端关闭连接
     *
     * @param $socket
     *
     * @return array
     */
    private function disconnect($socket)
    {
        unset($this->sockets[(int)$socket]);
        call_user_func_array($this->onClose, [$this, $socket]);
    }

    /**
     * 握手算法
     *
     * @param $socket
     * @param $buffer
     *
     * @return bool
     */
    public function handShake($socket, $buffer)
    {
        // 获取到客户端的升级密匙
        $line_with_key = substr($buffer, strpos($buffer, 'Sec-WebSocket-Key:') + 18);
        $key = trim(substr($line_with_key, 0, strpos($line_with_key, "\r\n")));

        // 生成升级密匙,并拼接websocket升级头
        $upgrade_key = base64_encode(sha1($key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));// 升级key的算法
        $upgrade_message = "HTTP/1.1 101 Switching Protocols\r\n";
        $upgrade_message .= "Upgrade: websocket\r\n";
        $upgrade_message .= "Sec-WebSocket-Version: 13\r\n";
        $upgrade_message .= "Connection: Upgrade\r\n";
        $upgrade_message .= "Sec-WebSocket-Accept:" . $upgrade_key . "\r\n\r\n";

        socket_write($socket, $upgrade_message, strlen($upgrade_message));// 向socket里写入升级信息
        $this->sockets[(int)$socket]['handshake'] = true;

        socket_getpeername($socket, $ip, $port);
        $this->debug([
            'hand_shake',
            $socket,
            $ip,
            $port
        ]);

        //执行回调
        call_user_func_array($this->onOpen, [$this, $socket]);

        return true;
    }

    /**
     * 发送消息
     * @param $socket
     * @param $msg
     */
    public function send($socket, $msg)
    {
        $msg = $this->build($msg);
        socket_write($socket, $msg, strlen($msg));
    }

    /**
     * 解析数据
     *
     * @param $buffer
     *
     * @return bool|string
     */
    private function parse($buffer)
    {
        $decoded = '';
        $len = ord($buffer[1]) & 127;
        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }

        return $decoded;
    }

    /**
     * 将普通信息组装成websocket数据帧
     *
     * @param $msg
     *
     * @return string
     */
    private function build($msg)
    {
        $frame = [];
        $frame[0] = '81';
        $len = strlen($msg);
        if ($len < 126) {
            $frame[1] = $len < 16 ? '0' . dechex($len) : dechex($len);
        } else if ($len < 65025) {
            $s = dechex($len);
            $frame[1] = '7e' . str_repeat('0', 4 - strlen($s)) . $s;
        } else {
            $s = dechex($len);
            $frame[1] = '7f' . str_repeat('0', 16 - strlen($s)) . $s;
        }

        $data = '';
        $l = strlen($msg);
        for ($i = 0; $i < $l; $i++) {
            $data .= dechex(ord($msg{$i}));
        }
        $frame[2] = $data;

        $data = implode('', $frame);

        return pack("H*", $data);
    }


    /**
     * 记录debug信息
     *
     * @param array $info
     */
    private function debug(array $info)
    {
        $time = date('Y-m-d H:i:s');
        array_unshift($info, $time);

        $info = array_map('json_encode', $info);
        file_put_contents(self::LOG_PATH . 'websocket_debug.log', implode(' | ', $info) . "\r\n", FILE_APPEND);
    }

    /**
     * 记录错误信息
     *
     * @param array $info
     */
    private function error(array $info)
    {
        $time = date('Y-m-d H:i:s');
        array_unshift($info, $time);

        $info = array_map('json_encode', $info);
        file_put_contents(self::LOG_PATH . 'websocket_error.log', implode(' | ', $info) . "\r\n", FILE_APPEND);
    }
}