<?php
/**
 * 核心公共函数：JSON 读写、数据访问、上传、CSRF、点赞去重、内容渲染等。
 */

/* ---------- 基础工具 ---------- */

/** 读取 JSON 文件，文件不存在或内容为空/损坏时返回默认值 */
function json_read($path, $default = []) {
    if (!file_exists($path)) return $default;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return $default;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

/** 写入 JSON 文件（带文件锁，防止并发写坏；非法编码自动替换，避免写空文件） */
function json_write($path, $data) {
    $json = json_encode($data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) $json = '[]';
    file_put_contents($path, $json, LOCK_EX);
}

/** HTML 转义，防止 XSS */
function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** 时间戳格式化 */
function format_time($ts) {
    return date('Y-m-d H:i', (int)$ts);
}

/** 生成摘要（截断为纯文本） */
function excerpt($text, $len = 120) {
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));
    if (mb_strlen($text) <= $len) return $text;
    return mb_substr($text, 0, $len) . '…';
}

/* ---------- 首次初始化 ---------- */

function init_data() {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0777, true);
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0777, true);

    // 管理员账号：默认 admin / admin123（登录后台后可在「修改密码」里更换）
    if (!file_exists(DATA_DIR . '/admin.json')) {
        json_write(DATA_DIR . '/admin.json', [
            'username'      => 'admin',
            'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
        ]);
    }

    $defaults = [
        'posts.json'    => [],
        'comments.json' => [],
        'likes.json'    => [],
        'settings.json' => ['site_name' => '我的博客', 'site_desc' => '记录生活，分享心情'],
    ];
    foreach ($defaults as $file => $default) {
        if (!file_exists(DATA_DIR . '/' . $file)) {
            json_write(DATA_DIR . '/' . $file, $default);
        }
    }

    // 上传目录防护：禁止列目录、禁止执行脚本（对 Apache 生效；内置服务器本就安全）
    if (!file_exists(UPLOAD_DIR . '/.htaccess')) {
        file_put_contents(UPLOAD_DIR . '/.htaccess',
            "Options -Indexes\n<FilesMatch \"\\.(php|php5|phtml|phar)$\">\n  Require all denied\n</FilesMatch>\n");
    }
    if (!file_exists(UPLOAD_DIR . '/index.html')) {
        file_put_contents(UPLOAD_DIR . '/index.html', '');
    }
}

/* ---------- 数据访问 ---------- */

function get_settings() {
    $s = json_read(DATA_DIR . '/settings.json', []);
    return $s + ['site_name' => '我的博客', 'site_desc' => '记录生活，分享心情'];
}
function save_settings($s) { json_write(DATA_DIR . '/settings.json', $s); }

function get_admin() { return json_read(DATA_DIR . '/admin.json', []); }
function save_admin($a) { json_write(DATA_DIR . '/admin.json', $a); }

function get_posts() { return json_read(DATA_DIR . '/posts.json', []); }
function save_posts($posts) { json_write(DATA_DIR . '/posts.json', $posts); }

function get_comments() { return json_read(DATA_DIR . '/comments.json', []); }
function save_comments($c) { json_write(DATA_DIR . '/comments.json', $c); }

function get_likes() { return json_read(DATA_DIR . '/likes.json', []); }
function save_likes($l) { json_write(DATA_DIR . '/likes.json', $l); }

/** 全部内容按创建时间倒序 */
function get_posts_sorted() {
    $posts = get_posts();
    usort($posts, function ($a, $b) {
        return ($b['created'] ?? 0) - ($a['created'] ?? 0);
    });
    return $posts;
}

function find_post($id) {
    foreach (get_posts() as $p) {
        if ($p['id'] === $id) return $p;
    }
    return null;
}

function like_count($id) {
    $l = get_likes();
    return isset($l[$id]) ? (int)$l[$id] : 0;
}

function get_post_comments($id) {
    $c = get_comments();
    return $c[$id] ?? [];
}

function comment_count($id) {
    return count(get_post_comments($id));
}

/** 生成唯一内容 ID */
function new_id() {
    return date('YmdHis') . '_' . bin2hex(random_bytes(3));
}

/** 新建或更新一篇文章（type=post 日志 / type=photo 相册图片） */
function save_post($data) {
    $posts = get_posts();
    $exists = false;
    foreach ($posts as &$p) {
        if ($p['id'] === $data['id']) {
            $p = array_merge($p, $data);
            $exists = true;
            break;
        }
    }
    unset($p);
    if (!$exists) $posts[] = $data;
    save_posts($posts);
}

/** 删除一篇文章，并清理其评论、点赞与关联的图片文件 */
function delete_post($id) {
    $posts = get_posts();
    $target = null;
    foreach ($posts as $p) {
        if ($p['id'] === $id) { $target = $p; break; }
    }
    $posts = array_values(array_filter($posts, function ($p) use ($id) {
        return $p['id'] !== $id;
    }));
    save_posts($posts);

    // 删除该文章引用的上传图片（仅限 uploads/ 下、确属本站上传的文件）
    if ($target && !empty($target['images'])) {
        foreach ($target['images'] as $img) {
            if (strpos($img, 'uploads/') !== 0) continue;
            $abs = ROOT_DIR . '/' . $img;
            if (file_exists($abs)) @unlink($abs);
        }
    }

    $c = get_comments(); unset($c[$id]); save_comments($c);
    $l = get_likes();    unset($l[$id]); save_likes($l);
}

/* ---------- 评论 ---------- */

function add_comment($post_id, $author, $content) {
    $c = get_comments();
    if (!isset($c[$post_id])) $c[$post_id] = [];
    $c[$post_id][] = [
        'id'      => 'c_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)),
        'author'  => $author,
        'content' => $content,
        'created' => time(),
    ];
    save_comments($c);
}

function delete_comment($comment_id) {
    $c = get_comments();
    foreach ($c as $pid => $list) {
        $c[$pid] = array_values(array_filter($list, function ($cm) use ($comment_id) {
            return $cm['id'] !== $comment_id;
        }));
        if (empty($c[$pid])) unset($c[$pid]);
    }
    save_comments($c);
}

/* ---------- 点赞（cookie 记录浏览器，每个浏览器每个内容最多赞一次） ---------- */

function get_liked_set() {
    $raw = $_COOKIE['liked_posts'] ?? '';
    $set = json_decode($raw, true);
    return is_array($set) ? $set : [];
}

function has_liked($id) {
    return in_array($id, get_liked_set());
}

function add_like($id) {
    $l = get_likes();
    $l[$id] = (isset($l[$id]) ? (int)$l[$id] : 0) + 1;
    save_likes($l);

    $set = get_liked_set();
    if (!in_array($id, $set)) {
        $set[] = $id;
        setcookie('liked_posts', json_encode($set), time() + 31536000, '/');
    }
}

/* ---------- CSRF 防护 ---------- */

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check() {
    return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf']);
}

/** 后台操作结果提示（一次性闪现） */
function flash($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

/* ---------- 图片上传 ---------- */

/** 校验并保存单个上传文件，返回 ['ok'=>bool, 'path'=>相对路径, 'error'=>错误信息] */
function validate_and_move($file) {
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => '没有收到文件'];
    }
    if ($file['size'] > 10 * 1024 * 1024) {
        return ['ok' => false, 'error' => '文件超过 10MB 限制'];
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return ['ok' => false, 'error' => '不是有效的图片文件'];
    }
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $ext = $extMap[$info['mime']] ?? null;
    if ($ext === null) {
        return ['ok' => false, 'error' => '仅支持 JPG / PNG / GIF / WebP 格式'];
    }
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . '/' . $name)) {
        return ['ok' => false, 'error' => '文件保存失败，请检查目录权限'];
    }
    return ['ok' => true, 'path' => 'uploads/' . $name];
}

/** 处理一个文件域（支持单文件或多文件数组），返回 ['paths'=>[], 'errors'=>[]] */
function handle_image_uploads($key) {
    $paths = [];
    $errors = [];
    if (empty($_FILES[$key])) {
        return ['paths' => $paths, 'errors' => ['没有收到文件']];
    }
    $files = $_FILES[$key];

    // 单文件
    if (!is_array($files['name'])) {
        $res = validate_and_move($files);
        if ($res['ok']) $paths[] = $res['path'];
        else $errors[] = $res['error'];
        return ['paths' => $paths, 'errors' => $errors];
    }

    // 多文件
    $n = count($files['name']);
    for ($i = 0; $i < $n; $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $one = [
            'name'     => $files['name'][$i],
            'type'     => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error'    => $files['error'][$i],
            'size'     => $files['size'][$i],
        ];
        $res = validate_and_move($one);
        if ($res['ok']) $paths[] = $res['path'];
        else $errors[] = $res['error'];
    }
    return ['paths' => $paths, 'errors' => $errors];
}

/* ---------- 内容渲染与安全过滤 ---------- */

/** 旧版纯文本 → 转义 + 分段后的安全 HTML */
function plain_to_html($text) {
    $text = trim(htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'));
    if ($text === '') return '';
    $paras = preg_split('/\r?\n\s*\r?\n/', $text);
    $html = [];
    foreach ($paras as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $html[] = '<p>' . nl2br($p) . '</p>';
    }
    return implode("\n", $html);
}

/** 渲染正文：html 格式（所见即所得编辑器产出）安全过滤后输出；text 格式（旧数据）转义分段 */
function render_content($post) {
    $content = $post['content'] ?? '';
    if (($post['format'] ?? 'text') === 'html') {
        return sanitize_html($content);
    }
    return plain_to_html($content);
}

/** 判断富文本是否为空（忽略空段落、换行、空格） */
function html_is_empty($html) {
    $s = str_replace(['&nbsp;', '<br>', '<br/>', '<br />'], ' ', (string)$html);
    return trim(strip_tags($s)) === '';
}

/** HTML 安全过滤：只保留白名单标签与属性，清除脚本、事件、危险链接 */
function sanitize_html($html) {
    $allowedTags  = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'del',
                     'h2', 'h3', 'h4', 'blockquote', 'ul', 'ol', 'li', 'a', 'img',
                     'code', 'pre', 'hr', 'div', 'span'];
    $allowedAttrs = [
        'a'   => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height'],
    ];

    $html = (string)$html;
    if (trim($html) === '') return '';

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . '<div>' . $html . '</div>', LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $root = $dom->documentElement;
    sanitize_nodes($root, $allowedTags, $allowedAttrs);

    $out = '';
    foreach ($root->childNodes as $c) {
        $out .= $dom->saveHTML($c);
    }
    return $out;
}

/** 递归清理 DOM 节点（仅被 sanitize_html 使用） */
function sanitize_nodes($node, $allowedTags, $allowedAttrs) {
    for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
        $child = $node->childNodes->item($i);

        if ($child->nodeType === XML_TEXT_NODE) continue;
        if ($child->nodeType !== XML_ELEMENT_NODE) {
            $node->removeChild($child);
            continue;
        }

        $tag = strtolower($child->nodeName);
        if (!in_array($tag, $allowedTags, true)) {
            // 非白名单标签：剥掉标签、保留其文本子节点
            $node->removeChild($child);
            // 先把子节点从被移除元素上逐个摘下来，再提升到父节点
            $moved = [];
            while ($child->firstChild) {
                $c = $child->firstChild;
                $child->removeChild($c);
                $moved[] = $c;
            }
            foreach ($moved as $c) $node->insertBefore($c, $node->childNodes->item($i));
            sanitize_nodes($node, $allowedTags, $allowedAttrs); // 重扫被提升的子节点
            return;
        }

        // 白名单标签：清理属性
        if ($child->attributes) {
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->nodeName);
                $val  = trim($attr->nodeValue);
                $ok   = isset($allowedAttrs[$tag]) && in_array($name, $allowedAttrs[$tag], true);
                if ($ok && in_array($name, ['href', 'src'], true)) {
                    $ok = !preg_match('#^(javascript|data|vbscript):#i', $val);
                }
                if (!$ok) $child->removeAttributeNode($attr);
            }
        }
        sanitize_nodes($child, $allowedTags, $allowedAttrs);
    }
}
