<?php

/**
 * Created by PhpStorm.
 * User: yf
 * Date: 2019-01-01
 * Time: 20:06
 */
return [
    'SERVER_NAME' => "EasySwoole",
    'MAIN_SERVER' => [
        'LISTEN_ADDRESS' => '0.0.0.0',
        'PORT' => 9501,
        'SERVER_TYPE' => EASYSWOOLE_WEB_SERVER, //可选为 EASYSWOOLE_SERVER  EASYSWOOLE_WEB_SERVER EASYSWOOLE_WEB_SOCKET_SERVER,EASYSWOOLE_REDIS_SERVER
        'SOCK_TYPE' => SWOOLE_TCP,
        'RUN_MODEL' => SWOOLE_PROCESS,
        'SETTING' => [
            'worker_num' => 8,
            'reload_async' => true,
            'max_wait_time' => 3
        ],
        'TASK' => [
            'workerNum' => 4,
            'maxRunningNum' => 128,
            'timeout' => 15
        ]
    ],
    'TEMP_DIR' => null,
    'LOG_DIR' => null,
    'REDIS' => [
        'host' => '127.0.0.1',
        'port' => '6379',
        'auth' => '',
        'db' => 1, //选择数据库,默认为0
        'intervalCheckTime' => 30 * 1000, //定时验证对象是否可用以及保持最小连接的间隔时间
        'maxIdleTime' => 15, //最大存活时间,超出则会每$intervalCheckTime/1000秒被释放
        'maxObjectNum' => 20, //最大创建数量
        'minObjectNum' => 5, //最小创建数量 最小创建数量不能大于等于最大创建   
    ]
];
