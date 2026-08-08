<?php
/**
 * 公共页面头部。使用前需设置：
 *   $siteName   站点名称
 *   $active     'home' | 'gallery' | 'admin' | ''（导航高亮项）
 *   $pageTitle  可选，页面标题
 */
$nav = [
    ['href' => 'index.php', 'label' => '首页', 'key' => 'home'],
    ['href' => 'gallery.php', 'label' => '相册', 'key' => 'gallery'],
    ['href' => 'admin.php', 'label' => '后台', 'key' => 'admin'],
];
$fullTitle = !empty($pageTitle) ? e($pageTitle) . ' · ' . e($siteName) : e($siteName);
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $fullTitle ?></title>
<link rel="stylesheet" href="assets/style.css?v=4">
<script>try{document.documentElement.setAttribute('data-theme', localStorage.getItem('blogTheme') || 'dark');}catch(e){}</script>
</head>
<body>
<header class="site-header">
  <div class="container header-inner">
    <a class="site-title" href="index.php"><span class="logo-dot" aria-hidden="true"></span><?= e($siteName) ?></a>
    <div class="header-right">
      <nav class="nav" aria-label="主导航">
        <?php foreach ($nav as $item): ?>
          <a href="<?= $item['href'] ?>"
             class="<?= ($active ?? '') === $item['key'] ? 'active' : '' ?>"><?= $item['label'] ?></a>
        <?php endforeach; ?>
      </nav>
      <button type="button" class="theme-toggle" id="themeToggle" aria-label="切换明暗主题" title="切换明暗主题">🌙</button>
    </div>
  </div>
</header>
