<?php

require_once __DIR__.'/../vendor/autoload.php';

use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\Process;
use Swoole\WebSocket\Frame;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Route;

/**
 * TODO List
 * 
 * - Abuse Control / x mesages per connection
 * - CORS Validation
 * - Limit payload size
 * - Message structure: { status: "ok"|"error", event: EVENT_NAME, data: <any arbitrary data> }
 * - JWT Authentication (in path / or in message)
 * 
 * Protocols Support:
 * - Websocket support: https://www.swoole.co.uk/docs/modules/swoole-websocket-server
 * - MQTT support: https://www.swoole.co.uk/docs/modules/swoole-mqtt-server
 * - SSE support: https://github.com/hhxsv5/php-sse
 * - Socket.io support: https://github.com/shuixn/socket.io-swoole-server
 */

ini_set('default_socket_timeout', -1);
Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

$server = new Server("0.0.0.0", 80);

$connections = [];

$server->on("workerStart", function ($server, $workerId) use (&$connections) {
    Console::success('Worker '.++$workerId.' started succefully');

    $attempts = 0;
    $start = time();
    
    while ($attempts < 300) {
        try {
            if($attempts > 0) {
                Console::error('Connection lost (lasted '.(time() - $start).' seconds). Attempting restart in 5 seconds (attempt #'.$attempts.')');
                sleep(5); // 1 sec delay between connection attempts
            }

            $redis = new Redis();
            $redis->connect('redis', 6379);
            $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);

            if($redis->ping(true)) {
                $attempts = 0;
                Console::success('Connection established');
            }
            else {
                Console::error('Connection failed');
            }

            $redis->subscribe(['realtime'], function($redis, $channel, $message) use ($server, $workerId, &$connections) {
                $message = 'Message from worker #'.$workerId.'; '.$message;
                        
                foreach($connections as $fd) {
                    if ($server->exist($fd)
                        && $server->isEstablished($fd)
                        ) {
                            Console::info('Sending message: '.$message.' (user: '.$fd.', worker: '.$workerId.')');

                            $server->push($fd, $message, SWOOLE_WEBSOCKET_OPCODE_TEXT,
                                SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS);
                    }
                    else { 
                        $server->close($fd); 
                    }
                }
            });
            
        } catch (\Throwable $th) {
            Console::error('Connection error: '.$th->getMessage());
            $attempts++;
            continue;
        }

        $attempts++;
    }

    Console::error('Failed to restart connection...');
});

$server->on("start", function (Server $server) {
    Console::success('Server started succefully');

    Console::info("Master pid {$server->master_pid}, manager pid {$server->manager_pid}");

    // listen ctrl + c
    Process::signal(2, function () use ($server) {
        Console::log('Stop by Ctrl+C');
        $server->shutdown();
    });
});

$server->on('open', function(Server $server, Request $request) use (&$connections) {
    $connections[] = $request->fd;
    
    Console::info("Connection open (user: {$request->fd}, worker: {$server->getWorkerId()})");
    Console::info('Total connections: '.count($connections));

    $app = new App('Asia/Tel_Aviv');

    var_dump($app->getResource('user'));

    $server->push($request->fd, json_encode(["hello", count($connections)]));
});

$server->on('message', function(Server $server, Frame $frame) {
    if($frame->data === 'reload') {
        $server->reload();
    }

    Console::info('Recieved message: '.$frame->data.' (user: '.$frame->fd.', worker: '.$server->getWorkerId().')');

    $server->push($frame->fd, json_encode(["hello, worker_id:".$server->getWorkerId(), time()]));
});

$server->on('close', function(Server $server, int $fd) {
    Console::error('Connection close: '.$fd);
});

$server->start();