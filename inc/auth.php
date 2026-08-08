<?php
/**
 * 后台登录校验（依赖 session，须在 bootstrap 之后使用）。
 */

function is_admin() {
    return !empty($_SESSION['admin']);
}

function require_admin() {
    if (!is_admin()) {
        header('Location: admin.php');
        exit;
    }
}
