<?php require __DIR__ . '/inc/bootstrap.php'; ?>
<?php
$settings = get_settings();
$siteName = $settings['site_name'];
$active   = 'admin';
$pageTitle = '后台管理';

/* ============ 登录处理（未登录时） ============ */
$loginErr = '';
if (!is_admin() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $admin = get_admin();
    $u = trim($_POST['username'] ?? '');
    $p = (string)($_POST['password'] ?? '');
    if ($u !== '' && $u === ($admin['username'] ?? '') && password_verify($p, $admin['password_hash'] ?? '')) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        header('Location: admin.php');
        exit;
    }
    $loginErr = '账号或密码错误';
}

/* ============ 后台操作（已登录时） ============ */
if (is_admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if (!csrf_check()) {
        flash('error', '校验失败，请刷新页面重试');
    } else {
        switch ($act) {

            case 'create_post':      // 写日志（富文本）
            case 'create_photo':     // 发相册图（纯文本描述）
                $title      = trim($_POST['title'] ?? '');
                $rawContent = (string)($_POST['content'] ?? '');
                $format     = ($_POST['format'] ?? 'text') === 'html' ? 'html' : 'text';
                $hasContent = $format === 'html' ? !html_is_empty($rawContent) : trim($rawContent) !== '';
                if ($title === '' && !$hasContent && empty($_FILES['images']['name'][0] ?? '')) {
                    flash('error', '标题、内容、图片至少填写一项');
                    break;
                }
                $content = $format === 'html' ? sanitize_html($rawContent) : trim($rawContent);
                $up = handle_image_uploads('images');
                $post = [
                    'id'      => new_id(),
                    'type'    => $act === 'create_photo' ? 'photo' : 'post',
                    'title'   => $title === '' ? '未命名' : $title,
                    'content' => $content,
                    'format'  => $format,
                    'images'  => $up['paths'],
                    'created' => time(),
                    'updated' => time(),
                ];
                save_post($post);
                flash('success', '发布成功！');
                if ($up['errors']) flash('error', '部分图片未上传：' . implode('；', $up['errors']));
                break;

            case 'update_post':      // 编辑保存
                $id = $_POST['id'] ?? '';
                $p  = find_post($id);
                if (!$p) { flash('error', '内容不存在'); break; }
                $up = handle_image_uploads('images');
                $format = ($_POST['format'] ?? 'text') === 'html' ? 'html' : 'text';
                $p['title']   = trim($_POST['title'] ?? '') ?: '未命名';
                $p['content'] = $format === 'html' ? sanitize_html($_POST['content'] ?? '') : trim($_POST['content'] ?? '');
                $p['format']  = $format;
                $p['images']  = array_merge($p['images'], $up['paths']);
                $p['updated'] = time();
                save_post($p);
                flash('success', '已保存');
                if ($up['errors']) flash('error', '部分图片未上传：' . implode('；', $up['errors']));
                break;

            case 'delete_post':
                if (find_post($_POST['id'] ?? '')) {
                    delete_post($_POST['id']);
                    flash('success', '已删除');
                }
                break;

            case 'delete_comment':
                delete_comment($_POST['id'] ?? '');
                flash('success', '评论已删除');
                break;

            case 'change_password':
                $admin = get_admin();
                $old     = (string)($_POST['old_password'] ?? '');
                $new     = (string)($_POST['new_password'] ?? '');
                $confirm = (string)($_POST['confirm_password'] ?? '');
                if (!password_verify($old, $admin['password_hash'] ?? '')) {
                    flash('error', '原密码不正确');
                } elseif (mb_strlen($new) < 6) {
                    flash('error', '新密码至少 6 位');
                } elseif ($new !== $confirm) {
                    flash('error', '两次输入的新密码不一致');
                } else {
                    $admin['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
                    save_admin($admin);
                    flash('success', '密码已修改，下次登录请用新密码');
                }
                break;

            case 'save_settings':
                $settings['site_name'] = trim($_POST['site_name'] ?? '') ?: '我的博客';
                $settings['site_desc'] = trim($_POST['site_desc'] ?? '');
                save_settings($settings);
                flash('success', '设置已保存');
                break;
        }
    }
    header('Location: admin.php');
    exit;
}

/* ============ 编辑模式 ============ */
$editing = null;
if (isset($_GET['edit'])) {
    $editing = find_post($_GET['edit']);
}

// 富文本编辑器初始内容：html 格式直接用已过滤内容，旧纯文本转成段落 HTML
$editorHtml = '';
if ($editing) {
    $editorHtml = ($editing['format'] ?? 'text') === 'html'
        ? $editing['content']
        : plain_to_html($editing['content']);
}

/* ============ 数据汇总 ============ */
$posts    = get_posts_sorted();
$comments = get_comments();
$flash    = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<?php require __DIR__ . '/inc/header.php'; ?>

<main class="container narrow">

<?php if (!is_admin()): ?>
  <!-- ===== 登录框 ===== -->
  <div class="login-box">
    <div class="login-head">
      <span class="logo-dot" aria-hidden="true"></span>
      <h1 class="login-title">后台登录</h1>
    </div>
    <p class="login-sub">登录后可管理内容、评论与站点设置</p>
    <?php if ($loginErr): ?><div class="flash error"><?= e($loginErr) ?></div><?php endif; ?>
    <form method="post" action="admin.php">
      <input type="hidden" name="action" value="login">
      <label>账号
        <input type="text" name="username" required autocomplete="username" placeholder="请输入管理员账号">
      </label>
      <label>密码
        <input type="password" name="password" required autocomplete="current-password" placeholder="请输入密码">
      </label>
      <button type="submit" class="btn login-btn">登 录</button>
    </form>
  </div>

<?php else: ?>
  <!-- ===== 管理面板 ===== -->
  <div class="admin-bar">
    <h2 class="page-title">后台管理</h2>
    <a class="btn ghost" href="logout.php">退出登录</a>
  </div>

  <?php if ($flash): ?>
    <div class="flash <?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="tabs">
    <button type="button" class="tab-btn active" data-tab="tab-write">✍️ 写日志</button>
    <button type="button" class="tab-btn" data-tab="tab-photo">📷 发相册图</button>
    <button type="button" class="tab-btn" data-tab="tab-manage">🗂️ 内容管理 (<?= count($posts) ?>)</button>
    <button type="button" class="tab-btn" data-tab="tab-comments">💬 评论管理</button>
    <button type="button" class="tab-btn" data-tab="tab-password">🔑 修改密码</button>
    <button type="button" class="tab-btn" data-tab="tab-settings">⚙️ 站点设置</button>
  </div>

  <!-- 写日志 -->
  <section class="tab-panel active" id="tab-write">
    <form method="post" enctype="multipart/form-data" action="admin.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $editing ? 'update_post' : 'create_post' ?>">
      <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
      <?php if ($editing): ?>
        <p class="hint">正在编辑：<strong><?= e($editing['title']) ?></strong>
        <a href="admin.php" class="hint-link">取消编辑</a></p>
      <?php endif; ?>
      <label>标题
        <input type="text" name="title" maxlength="100" value="<?= e($editing['title'] ?? '') ?>" placeholder="日志标题">
      </label>
      <div class="field-label">正文（所见即所得）</div>
      <div class="editor" data-editor>
        <div class="editor-toolbar" data-editor-toolbar>
          <button type="button" data-cmd="bold" title="加粗"><b>B</b></button>
          <button type="button" data-cmd="italic" title="斜体"><i>I</i></button>
          <button type="button" data-cmd="underline" title="下划线"><u>U</u></button>
          <button type="button" data-cmd="strikeThrough" title="删除线"><s>S</s></button>
          <span class="editor-sep"></span>
          <button type="button" data-cmd="formatBlock" data-value="h2" title="二级标题">H2</button>
          <button type="button" data-cmd="formatBlock" data-value="h3" title="三级标题">H3</button>
          <button type="button" data-cmd="formatBlock" data-value="blockquote" title="引用">❝</button>
          <span class="editor-sep"></span>
          <button type="button" data-cmd="insertUnorderedList" title="无序列表">≡•</button>
          <button type="button" data-cmd="insertOrderedList" title="有序列表">≡1</button>
          <button type="button" data-cmd="insertHr" title="插入分隔线">─</button>
          <span class="editor-sep"></span>
          <button type="button" data-cmd="createLink" title="插入链接">🔗</button>
          <button type="button" data-cmd="insertImage" title="插入图片（地址）">🖼</button>
          <button type="button" data-cmd="removeFormat" title="清除格式">⌫</button>
        </div>
        <div class="editor-content" contenteditable="true" data-editor-content placeholder="写点什么…"><?= $editorHtml ?></div>
        <input type="hidden" name="format" value="html">
        <input type="hidden" name="content" data-editor-hidden>
      </div>
      <p class="hint">工具栏可设置加粗 / 标题 / 引用 / 列表 / 链接等；「插入图片」填入地址即可内嵌图片。</p>
      <label>图片（可多选）
        <input type="file" name="images[]" accept="image/*" multiple>
      </label>
      <?php if ($editing && !empty($editing['images'])): ?>
        <div class="thumb-row">
          <?php foreach ($editing['images'] as $img): ?>
            <img class="thumb" src="<?= e($img) ?>" alt="">
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <button type="submit" class="btn"><?= $editing ? '保存修改' : '发布日志' ?></button>
    </form>
  </section>

  <!-- 发相册图 -->
  <section class="tab-panel" id="tab-photo">
    <form method="post" enctype="multipart/form-data" action="admin.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_photo">
      <label>标题（选填）
        <input type="text" name="title" maxlength="100" placeholder="图片标题">
      </label>
      <label>图片（可多选）
        <input type="file" name="images[]" accept="image/*" multiple required>
      </label>
      <label>描述（选填）
        <textarea name="content" rows="3" placeholder="这张图片的故事…"></textarea>
      </label>
      <button type="submit" class="btn">发布到相册</button>
    </form>
  </section>

  <!-- 内容管理 -->
  <section class="tab-panel" id="tab-manage">
    <?php if (!$posts): ?>
      <div class="empty">
        <span class="empty-emoji">🗂️</span>
        <p class="empty-text">还没有任何内容</p>
      </div>
    <?php endif; ?>
    <ul class="manage-list">
      <?php foreach ($posts as $p): ?>
        <li class="manage-item">
          <div class="manage-info">
            <span class="badge"><?= ($p['type'] ?? 'post') === 'photo' ? '相册' : '日志' ?></span>
            <a href="post.php?id=<?= e($p['id']) ?>"><?= e($p['title']) ?></a>
            <span class="date"><?= format_time($p['created']) ?></span>
          </div>
          <div class="manage-actions">
            <a class="btn mini ghost" href="?edit=<?= e($p['id']) ?>#tab-write">编辑</a>
            <form method="post" action="admin.php" onsubmit="return confirm('确定删除这篇文章吗？');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_post">
              <input type="hidden" name="id" value="<?= e($p['id']) ?>">
              <button type="submit" class="btn mini danger">删除</button>
            </form>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <!-- 评论管理 -->
  <section class="tab-panel" id="tab-comments">
    <?php if (!$comments): ?>
      <div class="empty">
        <span class="empty-emoji">💬</span>
        <p class="empty-text">还没有任何评论</p>
      </div>
    <?php endif; ?>
    <?php foreach ($comments as $pid => $list): ?>
      <?php $p = find_post($pid); ?>
      <div class="comment-group">
        <h3 class="comment-group-title">
          <a href="post.php?id=<?= e($pid) ?>"><?= e($p['title'] ?? '已删除的内容') ?></a>
        </h3>
        <ul class="comment-list">
          <?php foreach ($list as $cm): ?>
            <li class="comment-item" data-initial="<?= e(mb_substr($cm['author'], 0, 1)) ?>">
              <div class="comment-head">
                <span class="comment-author"><?= e($cm['author']) ?></span>
                <span class="comment-time"><?= format_time($cm['created']) ?></span>
                <form method="post" action="admin.php" onsubmit="return confirm('删除这条评论？');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_comment">
                  <input type="hidden" name="id" value="<?= e($cm['id']) ?>">
                  <button type="submit" class="btn mini danger">删除</button>
                </form>
              </div>
              <div class="comment-content"><?= e($cm['content']) ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- 修改密码 -->
  <section class="tab-panel" id="tab-password">
    <form method="post" action="admin.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="change_password">
      <label>原密码
        <input type="password" name="old_password" required autocomplete="current-password">
      </label>
      <label>新密码（至少 6 位）
        <input type="password" name="new_password" required minlength="6" autocomplete="new-password">
      </label>
      <label>确认新密码
        <input type="password" name="confirm_password" required minlength="6" autocomplete="new-password">
      </label>
      <button type="submit" class="btn">修改密码</button>
    </form>
  </section>

  <!-- 站点设置 -->
  <section class="tab-panel" id="tab-settings">
    <form method="post" action="admin.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_settings">
      <label>站点名称
        <input type="text" name="site_name" maxlength="50" value="<?= e($settings['site_name']) ?>">
      </label>
      <label>站点简介
        <input type="text" name="site_desc" maxlength="200" value="<?= e($settings['site_desc']) ?>">
      </label>
      <button type="submit" class="btn">保存设置</button>
    </form>
  </section>

<?php endif; ?>
</main>

<script src="assets/editor.js?v=4"></script>
<?php require __DIR__ . '/inc/footer.php'; ?>
