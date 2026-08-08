<?php require __DIR__ . '/inc/bootstrap.php'; ?>
<?php
$id   = $_GET['id'] ?? '';
$post = find_post($id);
$settings = get_settings();
$siteName = $settings['site_name'];
$active   = '';

if (!$post) {
    http_response_code(404);
    $pageTitle = '内容不存在';
    require __DIR__ . '/inc/header.php';
    ?>
    <main class="container">
      <div class="empty">
        <span class="empty-emoji">🧭</span>
        <p class="empty-text">内容不存在或已被删除</p>
        <a class="btn" href="index.php">返回首页</a>
      </div>
    </main>
    <?php
    require __DIR__ . '/inc/footer.php';
    exit;
}

$pageTitle = $post['title'];
$comments  = get_post_comments($id);
$liked     = has_liked($id);
$likeCount = like_count($id);
$isPhoto   = ($post['type'] ?? 'post') === 'photo';
?>
<?php require __DIR__ . '/inc/header.php'; ?>

<main class="container narrow">
  <article class="post-page">
    <div class="post-top">
      <?php if ($isPhoto): ?><span class="badge">相册图片</span><?php endif; ?>
      <span class="date"><?= format_time($post['created']) ?></span>
    </div>
    <h1 class="post-title"><?= e($post['title']) ?></h1>

    <?php if (!empty($post['content'])): ?>
      <div class="post-content"><?= render_content($post) ?></div>
    <?php endif; ?>

    <?php if (!empty($post['images'])): ?>
      <div class="post-gallery" data-lightbox>
        <?php foreach ($post['images'] as $img): ?>
          <figure class="post-gallery-item">
            <img src="<?= e($img) ?>" alt="<?= e($post['title']) ?>" loading="lazy">
          </figure>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="like-bar">
      <button class="btn-like<?= $liked ? ' liked' : '' ?>" data-post-id="<?= e($post['id']) ?>" <?= $liked ? 'disabled' : '' ?>>
        <span class="like-icon" aria-hidden="true">❤</span> 点赞
        <span class="like-count"><?= $likeCount ?></span>
      </button>
      <span class="like-hint"><?= $liked ? '你已赞过这篇文章' : '觉得不错就点个赞吧' ?></span>
    </div>
  </article>

  <section class="comments">
    <h2 class="section-title">评论（<?= comment_count($post['id']) ?>）</h2>

    <form class="comment-form" data-post-id="<?= e($post['id']) ?>">
      <input type="hidden" name="post_id" value="<?= e($post['id']) ?>">
      <div class="comment-inputs">
        <input type="text" name="name" placeholder="你的昵称（选填）" maxlength="20" autocomplete="nickname">
        <textarea name="content" rows="3" placeholder="说点什么吧…" required maxlength="500"></textarea>
      </div>
      <div class="comment-actions">
        <button type="submit" class="btn">发表评论</button>
        <span class="comment-msg" data-comment-msg></span>
      </div>
    </form>

    <ul class="comment-list">
      <?php if (!$comments): ?>
        <li class="empty">还没有评论，快来抢沙发～</li>
      <?php endif; ?>
      <?php foreach ($comments as $cm): ?>
        <li class="comment-item" data-initial="<?= e(mb_substr($cm['author'], 0, 1)) ?>">
          <div class="comment-head">
            <span class="comment-author"><?= e($cm['author']) ?></span>
            <span class="comment-time"><?= format_time($cm['created']) ?></span>
          </div>
          <div class="comment-content"><?= e($cm['content']) ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
</main>

<?php require __DIR__ . '/inc/footer.php'; ?>
