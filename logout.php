<?php
/** 退出后台登录 */
require __DIR__ . '/inc/bootstrap.php';

unset($_SESSION['admin']);
session_regenerate_id(true);
header('Location: admin.php');
exit;
