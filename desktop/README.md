# 博客管家（托盘应用）

把本地 PHP 博客装进系统托盘：开机点一下自动起服务，随时打开网站 / 后台，一键发布到 GitHub，不再需要 GitHub Desktop 或命令行。

## 使用方式

1. **启动**：双击 `启动博客.cmd`（或 `launcher.vbs`）。无窗口，右下角托盘出现蓝色圆点图标。
   - 首次启动会自动生成 `config.json`；PHP 在 `D:\php\php.exe`，站点在 `D:\github\mypage`，端口 `8000`。
   - 若端口 8000 已有博客服务在跑，会直接复用，不会重复启动。
2. **托盘图标右键菜单**：
   - 打开网站 → 浏览器访问本地站点
   - 打开后台管理 → 进入管理页写日志、发相册
   - 立即构建 docs → 手动执行 `php build.php`
   - 发布到 GitHub → 构建 + 提交 + 推送 `docs/` 到线上
   - 打开站点目录 → 资源管理器
   - 开机自启 → 勾选后开机自动启动（在启动文件夹建快捷方式）
   - 退出 → 停止本程序启动的 PHP 服务并退出托盘
3. **左键双击托盘图标** = 打开网站。

## 内容更新自动检测

在后台写完日志后，程序监听 `data/` 目录，3 秒后自动执行 `php build.php` 重建 `docs/`，并弹提示「内容已更新并重新构建」。之后到托盘点「发布到 GitHub」即可上线。

- `config.json` 的 `autoBuild`：内容变化后是否自动重建（默认 `true`）。
- `config.json` 的 `autoPush`：内容变化后是否**直接自动推送**（默认 `false`，避免误推；需要时改为 `true`）。
- 改动 `config.json` 后需重启程序生效。

## 首次发布：GitHub 登录（只需一次）

本机 git 默认没有 GitHub 凭据（之前一直用 GitHub Desktop 推送）。程序在首次发布时会自动执行
`git config credential.helper manager` 并重新推送，此时会**弹出 GitHub 登录窗口**：

> 完成一次登录后，凭据存入 Windows 凭据管理器，之后每次发布都全自动，无需再登录。

若没有弹出登录窗口或推送仍失败，可临时用命令行登录一次：

```bash
cd D:/github/mypage
git push        # 会弹出登录窗口
```

## 说明与排错

- **运行日志**：`desktop/blog-tray.log`，启动、构建、推送结果都在里面。
- **托盘气泡不显示？** Windows 通知设置可能屏蔽了气泡；操作结果仍会写入 `blog-tray.log`。
- **改 PHP 路径 / 站点目录 / 端口**：编辑 `desktop/config.json`。
- **彻底移除开机自启**：取消托盘「开机自启」勾选，或删除
  `%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\博客管家.lnk`。
- **本程序退出时**只会停止它自己启动的 PHP 服务；若是复用已有的服务则不会动它。

## 文件

```
desktop/
  blog-tray.ps1     主程序（托盘 + 服务管理 + git 发布 + data 监听）
  launcher.vbs      无窗口启动器
  启动博客.cmd      桌面入口
  config.json       配置（phpPath / siteDir / port / autoBuild / autoPush）
  blog-tray.log     运行日志（自动生成）
```
