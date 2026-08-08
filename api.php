<?php
/**
 * AJAX 接口：点赞、发表评论（均无需登录）。
 */
require __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function api_ok($data = []) {
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}
function api_fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_POST['action'] ?? ($_GET['action'] ?? '');

switch ($action) {

    /* ---- 点赞：每个浏览器每个内容只算一次 ---- */
    case 'like':
        $id = $_POST['post_id'] ?? '';
        if ($id === '' || !find_post($id)) api_fail('内容不存在', 404);
        if (!has_liked($id)) add_like($id);
        api_ok(['count' => like_count($id), 'liked' => true]);
        break;

    /* ---- 发表评论 ---- */
    case 'comment':
        $id      = $_POST['post_id'] ?? '';
        if ($id === '' || !find_post($id)) api_fail('内容不存在', 404);
        $name    = trim($_POST['name'] ?? '');
        $content = trim($_POST['content'] ?? '');
        if ($name === '') $name = '游客';
        if (mb_strlen($name) > 20) api_fail('昵称最多 20 个字');
        if ($content === '') api_fail('评论内容不能为空');
        if (mb_strlen($content) > 500) api_fail('评论最多 500 字');
        add_comment($id, $name, $content);
        api_ok(['message' => '评论成功']);
        break;

    default:
        api_fail('未知操作', 404);
}
