<?php
/**
 * 公共入口：启动会话、定义路径、初始化数据、引入函数库。
 * 每个页面第一行都应 require 本文件。
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

define('ROOT_DIR', dirname(__DIR__));
define('DATA_DIR', ROOT_DIR . '/data');
define('UPLOAD_DIR', ROOT_DIR . '/uploads');

require __DIR__ . '/functions.php';
require __DIR__ . '/auth.php';

init_data();
