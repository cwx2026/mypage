<?php
/**
 * 静态站点构建脚本（本地执行）
 *
 * 用法：php build.php
 *
 * 读取 data/*.json 并生成纯静态 HTML 到 docs/ 目录，
 * 之后把 docs/ 推到 GitHub，用 GitHub Pages 对外发布。
 *
 * 更新流程：先在本地用后台（php -S 127.0.0.1:8000）改内容，
 * 再运行本脚本重建，最后 git push。
 *
 * 说明：构建出的页面里所有链接都是绝对地址（带 SITE_URL），
 * 这样无论站点挂在子路径还是自定义域名下都能正常工作。
 */

/* ================= 配置 ================= */

/** 站点根地址（末尾带 /）。部署在 GitHub Pages 子路径时必填。 */
define('SITE_URL', 'https://cwx2026.github.io/mypage/');

/** 自定义域名（留空 = 不绑定，用上面的默认地址；绑定后会在 docs/CNAME 写入该域名）。 */
define('CUSTOM_DOMAIN', '');

/** 输出目录（即推送到 GitHub Pages 的目录） */
define('OUT_DIR', __DIR__ . '/docs');

/** giscus 评论区（可选）。先去 https://giscus.app 获取后填写。 */
define('GISCUS_REPO', 'cwx2026/mypage');   // 仓库名 owner/repo
define('GISCUS_REPO_ID', '');               // giscus 生成的 repo id
define('GISCUS_CATEGORY', 'Announcements'); // Discussion 分类名
define('GISCUS_CATEGORY_ID', '');           // giscus 生成的 category id

require __DIR__ . '/inc/bootstrap.php';

$giscus_on = GISCUS_REPO_ID !== '' && GISCUS_CATEGORY_ID !== '';

/* ================= 工具 ================= */

/** 生成带站点前缀的绝对链接 */
function site_url($path = '') {
    return SITE_URL . ltrim((string)$path, '/');
}

/** 递归删除目录 */
function rm_rf($dir) {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

/** 递归复制目录 */
function cp_r($src, $dst) {
    if (!is_dir($src)) return;
    if (!is_dir($dst)) mkdir($dst, 0777, true);
    foreach (scandir($src) as $f) {
        if ($f === '.' || $f === '..') continue;
        $s = $src . '/' . $f;
        $d = $dst . '/' . $f;
        is_dir($s) ? cp_r($s, $d) : copy($s, $d);
    }
}

/** 把正文富文本里的站内图片 / 站内链接改写为绝对地址 */
function absolutize_content($html) {
    $html = str_replace('src="uploads/', 'src="' . site_url('uploads/'), $html);
    $html = preg_replace_callback('#href="post\.php\?id=([^"&]+)"#', function ($m) {
        return 'href="' . site_url('post/' . $m[1] . '.html') . '"';
    }, $html);
    return $html;
}

/** 页面公共头部 */
function render_head($siteName, $pageTitle = '', $active = '', $desc = '', $canonical = '') {
    $nav = [
        ['href' => site_url('index.html'), 'label' => '首页', 'key' => 'home'],
        ['href' => site_url('gallery.html'), 'label' => '相册', 'key' => 'gallery'],
    ];
    $fullTitle = $pageTitle !== '' ? e($pageTitle) . ' · ' . e($siteName) : e($siteName);
    $descMeta = $desc !== '' ? '<meta name="description" content="' . e($desc) . '">' : '';
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $fullTitle ?></title>
<?= $descMeta ?>
<link rel="canonical" href="<?= site_url($canonical) ?>">
<link rel="stylesheet" href="<?= site_url('assets/style.css') ?>?v=4">
<script>try{document.documentElement.setAttribute('data-theme', localStorage.getItem('blogTheme') || 'dark');}catch(e){}</script>
</head>
<body>
<header class="site-header">
  <div class="container header-inner">
    <a class="site-title" href="<?= site_url('index.html') ?>"><span class="logo-dot" aria-hidden="true"></span><?= e($siteName) ?></a>
    <div class="header-right">
      <nav class="nav" aria-label="主导航">
        <?php foreach ($nav as $item): ?>
          <a href="<?= $item['href'] ?>"
             class="<?= $active === $item['key'] ? 'active' : '' ?>"><?= $item['label'] ?></a>
        <?php endforeach; ?>
      </nav>
      <button type="button" class="theme-toggle" id="themeToggle" aria-label="切换明暗主题" title="切换明暗主题">🌙</button>
    </div>
  </div>
</header>
    <?php
    return ob_get_clean();
}

/** 页面公共底部 */
function render_footer($siteName) {
    ob_start();
    ?>
<footer class="site-footer">
  <div class="container">
    <p class="footer-text">© <?= date('Y') ?> <?= e($siteName) ?></p>
    <p class="footer-sub">用 PHP + JSON 搭建的个人博客 · 静态化部署于 GitHub Pages</p>
  </div>
</footer>
<script src="<?= site_url('assets/app.js') ?>?v=3"></script>
</body>
</html>
    <?php
    return ob_get_clean();
}

/** giscus 评论嵌入（每篇文章按 post id 匹配对应 Discussion） */
function render_giscus($postId) {
    ob_start();
    ?>
<div class="giscus-note">欢迎在下方参与讨论（需登录 GitHub 账号）↓</div>
<script src="https://giscus.app/client.js"
        data-repo="<?= e(GISCUS_REPO) ?>"
        data-repo-id="<?= e(GISCUS_REPO_ID) ?>"
        data-category="<?= e(GISCUS_CATEGORY) ?>"
        data-category-id="<?= e(GISCUS_CATEGORY_ID) ?>"
        data-mapping="specific"
        data-term="<?= e($postId) ?>"
        data-strict="0"
        data-reactions-enabled="1"
        data-emit-metadata="0"
        data-input-position="bottom"
        data-theme="preferred_color_scheme"
        data-lang="zh-CN"
        crossorigin="anonymous"
        async>
</script>
    <?php
    return ob_get_clean();
}

/** 渲染首页（含分页），返回 HTML */
function render_index_page($list, $page, $pages, $siteName, $siteDesc) {
    $html = render_head($siteName, '', 'home', $siteDesc, '');
    $html .= '<section class="hero">'
          .  '<div class="hero-blob hero-blob-1" aria-hidden="true"></div>'
          .  '<div class="hero-blob hero-blob-2" aria-hidden="true"></div>'
          .  '<div class="container hero-inner">'
          .  '<h1 class="hero-title">' . e($siteName) . '</h1>';
    if ($siteDesc !== '') {
        $html .= '<p class="hero-desc">' . e($siteDesc) . '</p>';
    }
    $html .= '</div></section>';
    $html .= '<main class="container"><h2 class="section-title">最新日志</h2>';

    if (!$list) {
        $html .= '<div class="empty"><span class="empty-emoji">📝</span>'
              .  '<p class="empty-text">还没有内容，去本地后台写下第一篇日志吧</p></div>';
    }

    $html .= '<div class="post-list">';
    foreach ($list as $p) {
        $cover = $p['images'][0] ?? null;
        $postUrl = site_url('post/' . $p['id'] . '.html');
        $html .= '<article class="post-card">';
        if ($cover) {
            $html .= '<a class="post-cover" href="' . site_url($cover) . '">'
                  .  '<img src="' . site_url($cover) . '" alt="' . e($p['title']) . '" loading="lazy">'
                  .  '<span class="post-cover-fade" aria-hidden="true"></span></a>';
        }
        $html .= '<div class="post-card-body">'
              .  '<h2><a href="' . $postUrl . '">' . e($p['title']) . '</a></h2>'
              .  '<p class="post-excerpt">' . e(excerpt($p['content'] ?? '')) . '</p>'
              .  '<div class="post-meta">'
              .  '<span class="date">' . format_time($p['created']) . '</span>'
              .  '<span class="meta-item">❤ ' . like_count($p['id']) . '</span>'
              .  '<span class="meta-item">💬 ' . comment_count($p['id']) . '</span>'
              .  '</div></div></article>';
    }
    $html .= '</div>';

    if ($pages > 1) {
        $html .= '<div class="pagination">';
        if ($page > 1) {
            $prevFile = ($page - 1 === 1) ? 'index.html' : 'page-' . ($page - 1) . '.html';
            $html .= '<a href="' . site_url($prevFile) . '">« 上一页</a>';
        }
        $html .= '<span class="page-indicator">第 ' . $page . ' / ' . $pages . ' 页</span>';
        if ($page < $pages) {
            $html .= '<a href="' . site_url('page-' . ($page + 1) . '.html') . '">下一页 »</a>';
        }
        $html .= '</div>';
    }

    $html .= '</main>' . render_footer($siteName);
    return $html;
}

/** 渲染文章 / 图片详情页 */
function render_post_page($post, $comments, $likeCount, $siteName, $siteDesc, $giscusOn) {
    $id = $post['id'];
    $isPhoto = ($post['type'] ?? 'post') === 'photo';
    $html = render_head($siteName, $post['title'], '', $siteDesc, 'post/' . $id . '.html');

    $html .= '<main class="container narrow"><article class="post-page">';
    $html .= '<div class="post-top">';
    if ($isPhoto) $html .= '<span class="badge">相册图片</span>';
    $html .= '<span class="date">' . format_time($post['created']) . '</span></div>';
    $html .= '<h1 class="post-title">' . e($post['title']) . '</h1>';

    if (!empty($post['content'])) {
        $html .= '<div class="post-content">' . absolutize_content(render_content($post)) . '</div>';
    }

    if (!empty($post['images'])) {
        $html .= '<div class="post-gallery" data-lightbox>';
        foreach ($post['images'] as $img) {
            $html .= '<figure class="post-gallery-item"><img src="' . site_url($img) . '" alt="' . e($post['title']) . '" loading="lazy"></figure>';
        }
        $html .= '</div>';
    }

    // 点赞线上为只读：只展示数量，不提供交互按钮
    $html .= '<div class="like-bar">'
          .  '<span class="like-icon" aria-hidden="true">❤</span> '
          .  '<span class="like-count">' . $likeCount . '</span> 次点赞</div>';
    $html .= '</article>';

    // 评论：已有评论静态展示 + giscus 供访客留言
    $html .= '<section class="comments">';
    $html .= '<h2 class="section-title">评论（' . comment_count($id) . '）</h2>';
    if (!$comments) {
        $html .= '<p class="empty">还没有评论，快来抢沙发～</p>';
    } else {
        $html .= '<ul class="comment-list">';
        foreach ($comments as $cm) {
            $html .= '<li class="comment-item" data-initial="' . e(mb_substr($cm['author'], 0, 1)) . '">'
                  .  '<div class="comment-head">'
                  .  '<span class="comment-author">' . e($cm['author']) . '</span>'
                  .  '<span class="comment-time">' . format_time($cm['created']) . '</span>'
                  .  '</div>'
                  .  '<div class="comment-content">' . e($cm['content']) . '</div>'
                  .  '</li>';
        }
        $html .= '</ul>';
    }
    if ($giscusOn) {
        $html .= render_giscus($id);
    } else {
        $html .= '<p class="empty">评论区待配置（giscus）</p>';
    }
    $html .= '</section></main>' . render_footer($siteName);
    return $html;
}

/** 渲染相册页 */
function render_gallery_page($photos, $siteName, $siteDesc) {
    $html = render_head($siteName, '相册', 'gallery', $siteDesc, 'gallery.html');
    $html .= '<main class="container"><h2 class="section-title">相册</h2>';

    if (!$photos) {
        $html .= '<div class="empty"><span class="empty-emoji">📷</span>'
              .  '<p class="empty-text">相册还是空的，去本地后台发第一张图吧</p></div>';
    }

    $html .= '<div class="gallery" data-lightbox>';
    foreach ($photos as $p) {
        $img = $p['images'][0] ?? null;
        if (!$img) continue;
        $html .= '<figure class="gallery-item">'
              .  '<div class="gallery-media"><img src="' . site_url($img) . '" alt="' . e($p['title']) . '" loading="lazy"></div>'
              .  '<figcaption>'
              .  '<a href="' . site_url('post/' . $p['id'] . '.html') . '">' . e($p['title']) . '</a>'
              .  '<span class="meta-item">❤ ' . like_count($p['id']) . '</span>'
              .  '<span class="meta-item">💬 ' . comment_count($p['id']) . '</span>'
              .  '</figcaption></figure>';
    }
    $html .= '</div></main>' . render_footer($siteName);
    return $html;
}

/* ================= 构建主流程 ================= */

$settings = get_settings();
$siteName = $settings['site_name'];
$siteDesc = $settings['site_desc'];
$posts    = get_posts_sorted();

// 1. 清空并重建输出目录
rm_rf(OUT_DIR);
mkdir(OUT_DIR . '/post', 0777, true);

// 2. 复制静态资源与上传图片
cp_r(__DIR__ . '/assets', OUT_DIR . '/assets');
if (is_dir(__DIR__ . '/uploads')) cp_r(__DIR__ . '/uploads', OUT_DIR . '/uploads');

// 3. 首页（日志）——含分页，每页 8 篇
$logPosts = array_values(array_filter($posts, function ($p) {
    return ($p['type'] ?? 'post') === 'post';
}));
$perPage = 8;
$pages   = max(1, (int)ceil(count($logPosts) / $perPage));
for ($page = 1; $page <= $pages; $page++) {
    $list = array_slice($logPosts, ($page - 1) * $perPage, $perPage);
    $html = render_index_page($list, $page, $pages, $siteName, $siteDesc);
    $file = ($page === 1) ? 'index.html' : 'page-' . $page . '.html';
    file_put_contents(OUT_DIR . '/' . $file, $html);
    echo "  生成 {$file}\n";
}

// 4. 文章 / 图片详情页
foreach ($posts as $p) {
    $html = render_post_page(
        $p,
        get_post_comments($p['id']),
        like_count($p['id']),
        $siteName,
        $siteDesc,
        $giscus_on
    );
    file_put_contents(OUT_DIR . '/post/' . $p['id'] . '.html', $html);
    echo "  生成 post/{$p['id']}.html\n";
}

// 5. 相册页
$photos = array_values(array_filter($posts, function ($p) {
    return ($p['type'] ?? 'post') === 'photo';
}));
file_put_contents(OUT_DIR . '/gallery.html', render_gallery_page($photos, $siteName, $siteDesc));
echo "  生成 gallery.html\n";

// 6. 自定义域名文件（GitHub Pages 据此识别自定义域名，重建时保留）
if (CUSTOM_DOMAIN !== '') {
    file_put_contents(OUT_DIR . '/CNAME', CUSTOM_DOMAIN);
    echo "  生成 CNAME（" . CUSTOM_DOMAIN . "）\n";
}

echo "\n构建完成：docs/ 已更新（" . count($posts) . " 条内容）。\n";
echo '推送：git add docs && git commit -m "更新内容" && git push' . "\n";
