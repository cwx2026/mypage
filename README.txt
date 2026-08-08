个人博客网站 · 使用说明
========================

功能
----
- 写日志（可插图）、发相册图片
- 访客无需登录即可评论、点赞（本地版）
- 后台管理：发/删/编辑内容、管理评论、改密码、站点设置
- 纯 PHP 实现，数据全部存在 data/ 目录下的 JSON 文件中，无需数据库

本仓库说明
----------
本仓库是公开的，源码 + 静态站（docs/）一起维护：

- docs/  是 build.php 生成的纯静态站点，部署在 GitHub Pages，
         访客访问的就是这个目录。
- 其余 PHP 文件是本地管理端源码，用于在本地写内容。
- data/ 和 uploads/ 已加入 .gitignore，内容数据只保存在本地，
         不会上传到公开仓库（包含管理员账号哈希，切勿上传）。

如何本地管理内容
----------------
1. 在项目目录下执行：  php -S 127.0.0.1:8000
2. 浏览器打开：http://127.0.0.1:8000
3. 进入后台（右上角「后台」）发布 / 修改 / 删除内容。
   首次运行会自动创建管理员账号（默认账号见 inc/functions.php
   的 init_data()，登录后请立即在「修改密码」里更换）。

如何更新线上网站
----------------
每次改完内容后，重建静态站并推送：

    php build.php        # 重新生成 docs/
    git add docs
    git commit -m "更新内容"
    git push

（首次部署见仓库 Wiki / 下方「部署」）

部署（GitHub Pages）
--------------------
1. 仓库 cwx2026/mypage，Pages 设置选择：
   Source = Deploy from a branch，Branch = main，目录 = /docs。
2. 站点地址为 https://cwx2026.github.io/mypage/
3. 评论功能使用 giscus（GitHub Discussions 驱动）：
   - 在仓库 Settings 里开启 Discussions；
   - 安装 giscus app 并授权本仓库；
   - 打开 https://giscus.app 按提示配置，把生成的 repoId /
     categoryId 填到 build.php 顶部的 GISCUS_REPO_ID /
     GISCUS_CATEGORY_ID，重新 php build.php 并推送。
4. 若绑定了自定义域名，把 build.php 里的 SITE_URL 改为
   你的域名（末尾带 /），重新构建推送即可。

目录结构
--------
index.php      首页（日志列表）          admin.php     后台（本地使用）
post.php       日志/图片详情页（评论+点赞） api.php       点赞/评论 AJAX（本地使用）
gallery.php    相册页                    logout.php    退出登录
inc/           公共库（函数、登录校验、初始化）
data/          数据目录（JSON，仅本地，不入库）
uploads/       上传的图片（仅本地，不入库）
assets/        样式和脚本
docs/          构建输出的静态站点（推送到 GitHub Pages）
build.php      静态站点构建脚本

数据文件（仅本地）
-----------------
data/admin.json      管理员账号（含密码哈希）
data/posts.json      日志与相册内容
data/comments.json   评论
data/likes.json      点赞数
data/settings.json   站点名称与简介

注意
----
- 删除 data/ 或其中的 JSON 文件会清空对应数据，请谨慎操作。
- 如需重置管理员密码，可删除 data/admin.json 后重新访问，
  账号会重置为默认账号（见 init_data() 源码）。
