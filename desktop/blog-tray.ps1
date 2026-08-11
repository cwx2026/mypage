#requires -Version 5.1
<#
  博客管家（博客托盘）v1.0
  -----------------------------------------------------------------
  - 启动 / 复用本地 PHP 服务（php -S 127.0.0.1:端口）
  - 系统托盘：打开网站 / 打开后台 / 立即构建 docs / 发布到 GitHub / 打开目录 / 开机自启 / 退出
  - 监听 data/*.json 变化，自动重建 docs/ 并提示发布
  - 配置：同目录 config.json；日志：同目录 blog-tray.log

  启动：双击 desktop/launcher.vbs（或 desktop/启动博客.cmd），无窗口运行
  自测：powershell -NoProfile -ExecutionPolicy Bypass -File blog-tray.ps1 -Test
#>
param(
  [switch]$Test
)

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

$script:AppDir     = Split-Path -Parent $MyInvocation.MyCommand.Path
$script:ConfigPath = Join-Path $AppDir 'config.json'
$script:LogPath    = Join-Path $AppDir 'blog-tray.log'
$script:phpProc    = $null       # 由本程序启动的 PHP 进程（复用已有服务时为空）
$script:notifyIcon = $null

function Write-Log([string]$msg) {
  $line = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + '  ' + $msg
  try { Add-Content -LiteralPath $script:LogPath -Value $line -Encoding UTF8 } catch {}
}

function Load-Config {
  $def = [ordered]@{
    phpPath   = 'D:/php/php.exe'
    siteDir   = (Split-Path -Parent $script:AppDir)
    port      = 8000
    autoBuild = $true
    autoPush  = $false
    gitPath   = ''   # 空则用系统 PATH 里的 git；目标机安装包填便携版 git.exe
  }
  if (Test-Path -LiteralPath $script:ConfigPath) {
    try {
      $c = Get-Content -Raw -Encoding UTF8 -LiteralPath $script:ConfigPath | ConvertFrom-Json
      foreach ($k in $def.Keys) {
        if ($null -eq $c.$k) { $c | Add-Member -NotePropertyName $k -NotePropertyValue $def[$k] -Force }
      }
      return $c
    } catch {
      Write-Log "读取配置失败，使用默认：$_"
    }
  } else {
    $def | ConvertTo-Json | Set-Content -LiteralPath $script:ConfigPath -Encoding UTF8
    Write-Log "已生成默认配置：$($script:ConfigPath)"
  }
  return ($def | ForEach-Object { [pscustomobject]$_ })
}

function Quote-Arg([string]$s) {
  if ($s -match '[\s"]') { return '"' + ($s -replace '"', '\"') + '"' }
  return $s
}

function Invoke-Run {
  param([string]$Exe, [string[]]$Arguments, [string]$WorkDir)
  $psi = New-Object System.Diagnostics.ProcessStartInfo
  $psi.FileName = $Exe
  $psi.Arguments = (($Arguments | ForEach-Object { Quote-Arg $_ }) -join ' ')
  $psi.WorkingDirectory = $WorkDir
  $psi.UseShellExecute = $false
  $psi.RedirectStandardOutput = $true
  $psi.RedirectStandardError = $true
  $psi.CreateNoWindow = $true
  $p = [System.Diagnostics.Process]::Start($psi)
  $out = $p.StandardOutput.ReadToEnd()
  $err = $p.StandardError.ReadToEnd()
  $p.WaitForExit()
  return [pscustomobject]@{ ExitCode = $p.ExitCode; StdOut = $out; StdErr = $err }
}

function New-TrayIcon {
  $bmp = New-Object System.Drawing.Bitmap 32, 32
  $g = [System.Drawing.Graphics]::FromImage($bmp)
  $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
  $g.Clear([System.Drawing.Color]::Transparent)
  $brush = New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(64, 144, 255))
  $g.FillEllipse($brush, 3, 3, 26, 26)
  $pen = New-Object System.Drawing.Pen ([System.Drawing.Color]::White, 3)
  $g.DrawEllipse($pen, 11, 11, 10, 10)
  $g.Dispose()
  $icon = [System.Drawing.Icon]::FromHandle($bmp.GetHicon())
  return $icon
}

function Show-Tip([string]$msg, [System.Windows.Forms.ToolTipIcon]$icon = [System.Windows.Forms.ToolTipIcon]::Info) {
  try {
    $script:notifyIcon.BalloonTipTitle = '博客管家'
    $script:notifyIcon.BalloonTipText = $msg
    $script:notifyIcon.BalloonTipIcon = $icon
    $script:notifyIcon.ShowBalloonTip(6000)
  } catch {}
  Write-Log "提示：$msg"
}

function Open-Url([string]$u) {
  [System.Diagnostics.Process]::Start($u) | Out-Null
}

function Get-PortListening([int]$port) {
  return [bool](Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue)
}

function Get-ListeningPid([int]$port) {
  $c = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
  if ($c) { return [int]$c.OwningProcess }
  return 0
}

function Test-OwnServer([int]$port) {
  # 判断占用端口的进程是不是本站的 PHP 服务（按 php.exe 路径比对）
  $lp = Get-ListeningPid $port
  if ($lp -le 0) { return $null }
  try { $cmd = (Get-CimInstance Win32_Process -Filter "ProcessId=$lp" -ErrorAction Stop).CommandLine } catch { return $null }
  if (-not $cmd) { return $null }
  $cmdNorm = ($cmd -replace '\\', '/')
  $phpNorm = (($script:Cfg.phpPath -replace '\\', '/').TrimEnd('/'))
  return [bool]($cmdNorm -like "*$phpNorm*")
}

function Ensure-PhpServer {
  $port = [int]$script:Cfg.port
  if (-not (Test-Path -LiteralPath $script:Cfg.siteDir)) {
    Show-Tip "站点目录不存在：$($script:Cfg.siteDir)" ([System.Windows.Forms.ToolTipIcon]::Error)
    return $false
  }
  # 端口已有服务在响应 → 先确认是不是本站的 PHP 服务，是才复用；不是就明确提示，避免静默显示旧站
  try {
    $r = Invoke-WebRequest -Uri "http://127.0.0.1:$port/" -UseBasicParsing -TimeoutSec 2 -ErrorAction Stop
    if ($r.StatusCode -eq 200) {
      $mine = Test-OwnServer $port
      if ($mine -eq $true) {
        Write-Log "端口 $port 已有本站 PHP 服务，直接复用"
        return $true
      }
      if ($mine -eq $false) {
        Write-Log "端口 $port 被其他站点/旧安装占用（非本站目录），浏览器显示的可能是旧站"
        Show-Tip "端口 $port 被其他站点或旧安装占用，浏览器显示的可能是旧站。`n请先退出旧托盘、或停掉旧服务的 PHP 进程（任务管理器），再重新打开本站。" ([System.Windows.Forms.ToolTipIcon]::Warning)
        return $false
      }
      Write-Log "端口 $port 有服务响应（无法确认是否本站），直接复用"
      return $true
    }
  } catch {}
  if (Get-PortListening $port) {
    Write-Log "端口 $port 被其他程序占用（非博客服务），本站无法启动"
    Show-Tip "端口 $port 被其他程序占用，本站无法启动。请先释放该端口。" ([System.Windows.Forms.ToolTipIcon]::Error)
    return $false
  }
  if (-not (Test-Path -LiteralPath $script:Cfg.phpPath)) {
    Show-Tip "未找到 PHP：$($script:Cfg.phpPath)，请修改 desktop\config.json" ([System.Windows.Forms.ToolTipIcon]::Error)
    return $false
  }
  $p = Start-Process -FilePath $script:Cfg.phpPath -ArgumentList @('-S', "127.0.0.1:$port") -WorkingDirectory $script:Cfg.siteDir -WindowStyle Hidden -PassThru
  $script:phpProc = $p
  Start-Sleep -Milliseconds 900
  Write-Log "已启动 PHP 服务 PID=$($p.Id) 端口=$port"
  return $true
}

function Stop-PhpServer {
  if ($script:phpProc -and -not $script:phpProc.HasExited) {
    Stop-Process -Id $script:phpProc.Id -Force -ErrorAction SilentlyContinue
    Write-Log "已停止 PHP 服务 PID=$($script:phpProc.Id)"
    $script:phpProc = $null
  }
}

function Invoke-Build {
  $r = Invoke-Run -Exe $script:Cfg.phpPath -Arguments @('build.php') -WorkDir $script:Cfg.siteDir
  if ($r.ExitCode -eq 0) { Write-Log 'docs 构建成功'; return $true }
  Write-Log "docs 构建失败：$($r.StdErr.Trim())"
  return $false
}

function Invoke-Git {
  param([string[]]$Arguments)
  $git = $script:Cfg.gitPath
  if ([string]::IsNullOrWhiteSpace($git)) { $git = 'git' }
  return Invoke-Run -Exe $git -Arguments $Arguments -WorkDir $script:Cfg.siteDir
}

function Invoke-Publish {
  if (-not (Test-Path -LiteralPath (Join-Path $script:Cfg.siteDir '.git'))) {
    Show-Tip '未找到 .git 目录，不是 git 仓库' ([System.Windows.Forms.ToolTipIcon]::Error)
    return
  }
  # 1) 构建
  if (-not (Invoke-Build)) {
    Show-Tip '构建失败，未发布' ([System.Windows.Forms.ToolTipIcon]::Error)
    return
  }
  # 2) 是否真的有内容改动（内容数据 + 原图 + docs 都会体现）
  $st = Invoke-Git -Arguments @('status', '--porcelain')
  if (-not $st.StdOut.Trim()) {
    Show-Tip '没有需要发布的改动'
    return
  }
  # 3) 确保凭据助手（首次推送需登录 GitHub，一次即可）
  $ch = Invoke-Git -Arguments @('config', 'credential.helper')
  if (-not $ch.StdOut.Trim()) {
    Invoke-Git -Arguments @('config', 'credential.helper', 'manager') | Out-Null
    Write-Log '已为本仓库启用 git credential.helper=manager'
  }
  # 4) 提交全部改动（docs/ + 内容数据 + 原图）
  Invoke-Git -Arguments @('add', '-A') | Out-Null
  $commit = Invoke-Git -Arguments @('commit', '-m', '更新内容')
  if ($commit.ExitCode -ne 0 -and $commit.StdOut -notmatch 'nothing to commit') {
    Show-Tip ("提交失败：" + (($commit.StdErr.Trim()) -replace "`r?`n", ' ')) ([System.Windows.Forms.ToolTipIcon]::Error)
    return
  }
  # 5) 推送
  Show-Tip '正在发布到 GitHub…'
  $push = Invoke-Git -Arguments @('push')
  if ($push.ExitCode -eq 0) {
    Show-Tip '已成功发布到 GitHub ✓'
  } else {
    Write-Log "push 失败：$($push.StdErr.Trim())"
    Show-Tip '推送失败：若弹出 GitHub 登录窗口请先完成登录再重试「发布」；或命令行执行 git push 查看详情' ([System.Windows.Forms.ToolTipIcon]::Warning)
  }
}

function Invoke-Pull {
  if (-not (Test-Path -LiteralPath (Join-Path $script:Cfg.siteDir '.git'))) {
    Show-Tip '未找到 .git 目录，不是 git 仓库' ([System.Windows.Forms.ToolTipIcon]::Error)
    return
  }
  # --autostash 保护本地未提交改动；--rebase 保持线性历史
  Show-Tip '正在从 GitHub 拉取最新…'
  $r = Invoke-Git -Arguments @('pull', '--rebase', '--autostash')
  if ($r.ExitCode -eq 0) {
    Show-Tip '已同步到 GitHub 最新 ✓'
  } else {
    Write-Log "pull 失败：$($r.StdErr.Trim())"
    Show-Tip ('拉取失败：' + (($r.StdErr.Trim()) -replace "`r?`n", ' ')) ([System.Windows.Forms.ToolTipIcon]::Warning)
  }
}

# ---------- 开机自启 ----------
function Get-StartupLnk {
  return Join-Path $env:APPDATA 'Microsoft\Windows\Start Menu\Programs\Startup\博客管家.lnk'
}
function Test-AutoStart { return (Test-Path -LiteralPath (Get-StartupLnk)) }
function Set-AutoStart([bool]$on) {
  $lnk = Get-StartupLnk
  if ($on) {
    try {
      $ws = New-Object -ComObject WScript.Shell
      $sc = $ws.CreateShortcut($lnk)
      $sc.TargetPath = Join-Path $script:AppDir 'launcher.vbs'
      $sc.WorkingDirectory = $script:AppDir
      $sc.Description = '博客管家：启动本地博客服务到托盘'
      $sc.Save()
      Write-Log "已开启开机自启：$lnk"
    } catch { Write-Log "开机自启设置失败：$_" }
  } else {
    if (Test-Path -LiteralPath $lnk) { Remove-Item -LiteralPath $lnk; Write-Log '已关闭开机自启' }
  }
}

# ---------- 卸载 ----------
function Uninstall-BlogManager {
  $installRoot = Split-Path -Parent $script:Cfg.siteDir
  $msg = "确定卸载「博客管家」吗？`n`n将停止本站服务，并删除：`n  $installRoot`n（含 PHP、git、站点内容 site\）`n以及桌面快捷方式、开机自启。`n`n已发布到 GitHub 的内容不受影响；仅本机未发布的改动会丢失。`nGitHub 令牌保留在 %USERPROFILE%\.git-credentials，如需移除请自行删除该文件。"
  $r = [System.Windows.Forms.MessageBox]::Show($msg, '卸载博客管家', [System.Windows.Forms.MessageBoxButtons]::YesNo, [System.Windows.Forms.MessageBoxIcon]::Question, [System.Windows.Forms.MessageBoxDefaultButton]::Button2)
  if ($r -ne [System.Windows.Forms.DialogResult]::Yes) { return }
  # 1) 停止本站 PHP 服务（含“复用”情形：按端口 + php 路径定位后停掉）
  $lp = Get-ListeningPid $script:Cfg.port
  if ($lp -gt 0) {
    try {
      $cmd = (Get-CimInstance Win32_Process -Filter "ProcessId=$lp" -ErrorAction Stop).CommandLine
      if ($cmd -and ($cmd -replace '\\', '/') -like "*$(($script:Cfg.phpPath -replace '\\', '/').TrimEnd('/'))*") {
        Stop-Process -Id $lp -Force -ErrorAction SilentlyContinue
      }
    } catch {}
  }
  Stop-PhpServer
  # 2) 删除快捷方式：桌面 + 开机启动
  foreach ($lnk in @((Join-Path ([Environment]::GetFolderPath('Desktop')) '博客管家.lnk'), (Get-StartupLnk))) {
    if (Test-Path -LiteralPath $lnk) { Remove-Item -LiteralPath $lnk -Force -ErrorAction SilentlyContinue }
  }
  # 3) 延时删除安装目录（cmd 在临时目录运行，等本进程退出后再删，避免占用自身目录）
  Start-Process cmd.exe -ArgumentList '/c', ("timeout /t 3 /nobreak >nul & rmdir /s /q `"{0}`"" -f $installRoot) -WindowStyle Hidden -WorkingDirectory $env:TEMP
  Write-Log "已启动卸载，删除目录：$installRoot"
  [System.Windows.Forms.Application]::Exit()
}

# ---------- 配置 ----------
$script:Cfg = Load-Config

# ---------- 自测模式 ----------
if ($Test) {
  Write-Host '博客管家自测：'
  Write-Host ("  配置：{0}" -f (($script:Cfg | ConvertTo-Json -Compress)))
  try {
    $phpV = Invoke-Run -Exe $script:Cfg.phpPath -Arguments @('-v') -WorkDir $script:Cfg.siteDir
    Write-Host ("  PHP：exit={0}  {1}" -f $phpV.ExitCode, ($phpV.StdOut -split "`r?`n")[0])
  } catch { Write-Host "  PHP：不可用 - $_" }
  try {
    $gv = Invoke-Run -Exe 'git' -Arguments @('--version') -WorkDir $script:Cfg.siteDir
    Write-Host ("  git：{0}" -f $gv.StdOut.Trim())
  } catch { Write-Host '  git：不可用' }
  $ok = Ensure-PhpServer
  Write-Host ("  服务：{0}" -f $(if ($ok) { 'OK' } else { '失败' }))
  Stop-PhpServer
  Write-Host '自测完成。'
  exit 0
}

# ---------- 单实例：防止重复启动 ----------
$script:singleMutex = New-Object System.Threading.Mutex($false, 'BlogTray_20051023_SingleInstance')
try { $script:mutexHeld = $script:singleMutex.WaitOne(0, $false) }
catch { $script:mutexHeld = $true }   # 上次异常退出遗留的互斥锁 → 视为可接管
$script:signalEvent = $null
if (-not $script:mutexHeld) {
  # 已有实例在运行：发出信号让原实例弹提示，然后本实例静默退出
  Write-Log '检测到博客管家已在运行，通知原实例后退出'
  try { (New-Object System.Threading.EventWaitHandle($false, 'AutoReset', 'BlogTray_20051023_Signal')).Set() } catch {}
  exit 0
}
# 创建信号事件：重复启动的实例会 Set 它，本实例定时器检测到后弹提示
$script:signalEvent = New-Object System.Threading.EventWaitHandle($false, 'AutoReset', 'BlogTray_20051023_Signal')

# ---------- 托盘界面 ----------
$script:notifyIcon = New-Object System.Windows.Forms.NotifyIcon
$script:notifyIcon.Text = '博客管家'
$script:notifyIcon.Icon = New-TrayIcon
$script:notifyIcon.Visible = $true

$menu = New-Object System.Windows.Forms.ContextMenuStrip

$miSite    = New-Object System.Windows.Forms.ToolStripMenuItem('打开网站')
$miAdmin   = New-Object System.Windows.Forms.ToolStripMenuItem('打开后台管理')
$miBuild   = New-Object System.Windows.Forms.ToolStripMenuItem('立即构建 docs')
$miPublish = New-Object System.Windows.Forms.ToolStripMenuItem('发布到 GitHub')
$miPull    = New-Object System.Windows.Forms.ToolStripMenuItem('从 GitHub 拉取最新')
$miDir     = New-Object System.Windows.Forms.ToolStripMenuItem('打开站点目录')
$miAuto    = New-Object System.Windows.Forms.ToolStripMenuItem('开机自启')
$miExit    = New-Object System.Windows.Forms.ToolStripMenuItem('退出')
$miUninst  = New-Object System.Windows.Forms.ToolStripMenuItem('卸载博客管家…')

$miSite.Add_Click({ Open-Url "http://127.0.0.1:$($script:Cfg.port)/" })
$miAdmin.Add_Click({ Open-Url "http://127.0.0.1:$($script:Cfg.port)/admin.php" })
$miBuild.Add_Click({
  if (Invoke-Build) { Show-Tip 'docs 已重新构建' }
  else { Show-Tip '构建失败，详见 blog-tray.log' ([System.Windows.Forms.ToolTipIcon]::Error) }
})
$miPublish.Add_Click({ Invoke-Publish })
$miPull.Add_Click({ Invoke-Pull })
$miDir.Add_Click({
  # explorer 不认正斜杠路径（/ 会被当作开关解析而退回到“文档”），转反斜杠并加引号
  $dir = ($script:Cfg.siteDir -replace '/', '\')
  Start-Process 'explorer.exe' -ArgumentList "`"$dir`""
})
$miAuto.CheckOnClick = $true
$miAuto.Checked = (Test-AutoStart)
$miAuto.Add_Click({ Set-AutoStart ([bool]$miAuto.Checked) })
$miExit.Add_Click({ [System.Windows.Forms.Application]::Exit() })
$miUninst.Add_Click({ Uninstall-BlogManager })

$null = $menu.Items.Add($miSite)
$null = $menu.Items.Add($miAdmin)
$null = $menu.Items.Add('-')
$null = $menu.Items.Add($miBuild)
$null = $menu.Items.Add($miPublish)
$null = $menu.Items.Add($miPull)
$null = $menu.Items.Add('-')
$null = $menu.Items.Add($miDir)
$null = $menu.Items.Add($miAuto)
$null = $menu.Items.Add('-')
$null = $menu.Items.Add($miUninst)
$null = $menu.Items.Add($miExit)

$script:notifyIcon.ContextMenuStrip = $menu
$script:notifyIcon.add_DoubleClick({ Open-Url "http://127.0.0.1:$($script:Cfg.port)/" })

# ---------- 监听 data/ 变化（防抖 3 秒） ----------
$script:dataDirty = $false
$script:dataDirtyAt = $null
function On-DataChange {
  $script:dataDirty = $true
  $script:dataDirtyAt = Get-Date
}

$watcher = New-Object System.IO.FileSystemWatcher
$watcher.Path = (Join-Path $script:Cfg.siteDir 'data')
$watcher.Filter = '*.json'
$watcher.IncludeSubdirectories = $false
try { $watcher.EnableRaisingEvents = $true } catch { Write-Log "监听 data/ 失败：$_" }
$watcher.add_Changed({ On-DataChange })
$watcher.add_Created({ On-DataChange })
$watcher.add_Deleted({ On-DataChange })
$watcher.add_Renamed({ On-DataChange })

$timer = New-Object System.Windows.Forms.Timer
$timer.Interval = 1500
$timer.add_Tick({
  # 收到「重复启动」信号 → 提示已在运行
  if ($script:signalEvent -and $script:signalEvent.WaitOne(0)) {
    Show-Tip '博客管家已在运行。'
  }
  if ($script:dataDirty) {
    $elapsed = ((Get-Date) - $script:dataDirtyAt).TotalSeconds
    if ($elapsed -ge 3) {
      $script:dataDirty = $false
      if ($script:Cfg.autoBuild) {
        if (Invoke-Build) {
          Show-Tip '内容已更新并重新构建。点击托盘「发布到 GitHub」上线'
        } else {
          Show-Tip '内容已更新但构建失败，详见 blog-tray.log' ([System.Windows.Forms.ToolTipIcon]::Error)
        }
      }
      if ($script:Cfg.autoPush) { Invoke-Publish }
    }
  }
})
$timer.Start()

# ---------- 主流程 ----------
try {
  Write-Log '博客管家启动。'
  Ensure-PhpServer | Out-Null
  Show-Tip '博客管家已就绪。右键托盘图标进行操作'
  [System.Windows.Forms.Application]::Run()
} catch {
  Write-Log "主流程异常：$_"
} finally {
  $timer.Stop()
  $timer.Dispose()
  Stop-PhpServer
  try { $script:notifyIcon.Visible = $false; $script:notifyIcon.Dispose() } catch {}
  if ($script:singleMutex) {
    try { if ($script:mutexHeld) { $script:singleMutex.ReleaseMutex() } } catch {}
    $script:singleMutex.Dispose()
  }
  if ($script:signalEvent) { try { $script:signalEvent.Dispose() } catch {} }
  Write-Log '博客管家已退出。'
}
