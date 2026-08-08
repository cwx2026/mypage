<?php require __DIR__ . '/inc/bootstrap.php'; ?>
<?php
$settings = get_settings();
$siteName = $settings['site_name'];
$siteDesc = $settings['site_desc'];
$active   = 'home';

// 只展示日志，相册单独在 gallery.php
$posts = array_values(array_filter(get_posts_sorted(), function ($p) {
    return ($p['type'] ?? 'post') === 'post';
}));

$perPage = 8;
$total   = count($posts);
$pages   = max(1, (int)ceil($total / $perPage));
$page    = max(1, min((int)($_GET['page'] ?? 1), $pages));
$offset  = ($page - 1) * $perPage;
$list    = array_slice($posts, $offset, $perPage);
?>
<?php require __DIR__ . '/inc/header.php'; ?>

<section class="hero">
  <div class="hero-blob hero-blob-1" aria-hidden="true"></div>
  <div class="hero-blob hero-blob-2" aria-hidden="true"></div>
  <div class="container hero-inner">
    <h1 class="hero-title"><?= e($siteName) ?></h1>
    <?php if ($siteDesc): ?>
      <p class="hero-desc"><?= e($siteDesc) ?></p>
    <?php endif; ?>
  </div>
</section>

<main class="container">
  <h2 class="section-title">最新日志</h2>

  <?php if (!$list): ?>
    <div class="empty">
      <span class="empty-emoji">📝</span>
      <p class="empty-text">还没有内容，来写下第一篇日志吧</p>
      <a class="btn" href="admin.php">去后台发布</a>
    </div>
  <?php endif; ?>

  <div class="post-list">
    <?php foreach ($list as $p): ?>
      <?php $cover = $p['images'][0] ?? null; ?>
      <article class="post-card">
        <?php if ($cover): ?>
        <a class="post-cover" href="post.php?id=<?= e($p['id']) ?>">
          <img src="<?= e($cover) ?>" alt="<?= e($p['title']) ?>" loading="lazy">
          <span class="post-cover-fade" aria-hidden="true"></span>
        </a>
        <?php endif; ?>
        <div class="post-card-body">
          <h2><a href="post.php?id=<?= e($p['id']) ?>"><?= e($p['title']) ?></a></h2>
          <p class="post-excerpt"><?= e(excerpt($p['content'] ?? '')) ?></p>
          <div class="post-meta">
            <span class="date"><?= format_time($p['created']) ?></span>
            <span class="meta-item">❤ <?= like_count($p['id']) ?></span>
            <span class="meta-item">💬 <?= comment_count($p['id']) ?></span>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <?php if ($pages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>">« 上一页</a><?php endif; ?>
      <span class="page-indicator">第 <?= $page ?> / <?= $pages ?> 页</span>
      <?php if ($page < $pages): ?><a href="?page=<?= $page + 1 ?>">下一页 »</a><?php endif; ?>
    </div>
  <?php endif; ?>
</main>

<?php require __DIR__ . '/inc/footer.php'; ?>
