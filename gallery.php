<?php require __DIR__ . '/inc/bootstrap.php'; ?>
<?php
$settings = get_settings();
$siteName = $settings['site_name'];
$active   = 'gallery';
$pageTitle = '相册';
$photos = array_values(array_filter(get_posts_sorted(), function ($p) {
    return ($p['type'] ?? 'post') === 'photo';
}));
?>
<?php require __DIR__ . '/inc/header.php'; ?>

<main class="container">
  <h2 class="section-title">相册</h2>

  <?php if (!$photos): ?>
    <div class="empty">
      <span class="empty-emoji">📷</span>
      <p class="empty-text">相册还是空的，来发第一张图吧</p>
      <a class="btn" href="admin.php">去后台发布</a>
    </div>
  <?php endif; ?>

  <div class="gallery" data-lightbox>
    <?php foreach ($photos as $p): ?>
      <?php $img = $p['images'][0] ?? null; if (!$img) continue; ?>
      <figure class="gallery-item">
        <div class="gallery-media">
          <img src="<?= e($img) ?>" alt="<?= e($p['title']) ?>" loading="lazy">
        </div>
        <figcaption>
          <a href="post.php?id=<?= e($p['id']) ?>"><?= e($p['title']) ?></a>
          <span class="meta-item">❤ <?= like_count($p['id']) ?></span>
          <span class="meta-item">💬 <?= comment_count($p['id']) ?></span>
        </figcaption>
      </figure>
    <?php endforeach; ?>
  </div>
</main>

<?php require __DIR__ . '/inc/footer.php'; ?>
