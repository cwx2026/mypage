#requires -Version 5.1
<#
  博客管家 一键安装程序（由 IExpress 安装包解压后调用，运行在目标电脑上）

  功能：
  1. 拷贝内置 PHP 运行时 + 便携版 git 到 %LOCALAPPDATA%\博客管家
  2. 检查 site/ 内容：没有 → git clone 拉取最新；已有 → git pull 更新
     （即「安装后先检查内容，若不全/旧就自动拉取」）
  3. 填一次 GitHub 用户名 + 访问令牌(PAT)，供「发布到 GitHub」使用
  4. 生成 desktop/config.json，创建桌面快捷方式
  5. 启动托盘应用「博客管家」
#>

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms

$Payload   = $PSScriptRoot                 # IExpress 解压临时目录（含 php/ git/ setup.ps1）
$Install   = Join-Path $env:LOCALAPPDATA '博客管家'
$SiteDir   = Join-Path $Install 'site'
$PhpDir    = Join-Path $Install 'php'
$GitDir    = Join-Path $Install 'git'
$GitExe    = Join-Path $GitDir 'cmd\git.exe'
$PhpExe    = Join-Path $PhpDir 'php.exe'
$DeskDir   = Join-Path $SiteDir 'desktop'
$RepoUrl   = 'https://github.com/cwx2026/mypage.git'
$LogFile   = Join-Path $Install 'install.log'

# 测试模式（BLOG_SETUP_TEST=1）：跳过 PAT 表单 / 桌面快捷方式 / 托盘启动，用于构建冒烟测试
$TestMode = $env:BLOG_SETUP_TEST -eq '1'

function Log([string]$m) {
  $line = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') + '  ' + $m
  try { Add-Content -LiteralPath $LogFile -Value $line -Encoding UTF8 } catch {}
}

function Die([string]$msg) {
  Log "失败：$msg"
  if (-not $script:TestMode) {
    [void][System.Windows.Forms.MessageBox]::Show("$msg`n`n详情见：$LogFile", '博客管家 安装失败', 'OK', 'Error')
  }
  exit 1
}

function Run-Capture {
  param([string]$Exe, [string[]]$Arg, [string]$Dir)
  $psi = New-Object System.Diagnostics.ProcessStartInfo
  $psi.FileName = $Exe
  $psi.Arguments = (($Arg | ForEach-Object {
    if ($_ -match '[\s"]') { '"' + ($_ -replace '"', '\"') + '"' } else { $_ }
  }) -join ' ')
  $psi.WorkingDirectory = $Dir
  $psi.UseShellExecute = $false
  $psi.RedirectStandardOutput = $true
  $psi.RedirectStandardError = $true
  $psi.CreateNoWindow = $true
  $p = [System.Diagnostics.Process]::Start($psi)
  $out = $p.StandardOutput.ReadToEnd()
  $err = $p.StandardError.ReadToEnd()
  $p.WaitForExit()
  return [pscustomobject]@{ Code = $p.ExitCode; Out = $out; Err = $err }
}

# ---------- GitHub 登录表单（用户名 + PAT） ----------
function Get-GitCreds {
  $form = New-Object System.Windows.Forms.Form
  $form.Text = '博客管家 - GitHub 发布登录'
  $form.ClientSize = New-Object System.Drawing.Size(560, 250)
  $form.StartPosition = 'CenterScreen'
  $form.FormBorderStyle = 'FixedDialog'
  $form.MaximizeBox = $false
  $form.MinimizeBox = $false

  $lblTip = New-Object System.Windows.Forms.Label
  $lblTip.Text = "发布内容需要 GitHub 登录，只需填这一次。`n先在浏览器打开 https://github.com/settings/tokens 创建令牌：`n   → Generate new token → 勾选 repo → 复制生成的字符串（ghp_ 开头）。`n令牌仅保存在本机，用于把内容推回你的仓库。"
  $lblTip.Location = New-Object System.Drawing.Point(16, 14)
  $lblTip.Size = New-Object System.Drawing.Size(528, 92)
  $lblTip.AutoSize = $false

  $lblUser = New-Object System.Windows.Forms.Label
  $lblUser.Text = 'GitHub 用户名：'
  $lblUser.Location = New-Object System.Drawing.Point(16, 116)
  $lblUser.Size = New-Object System.Drawing.Size(120, 22)

  $txtUser = New-Object System.Windows.Forms.TextBox
  $txtUser.Location = New-Object System.Drawing.Point(142, 112)
  $txtUser.Size = New-Object System.Drawing.Size(250, 24)

  $lblPat = New-Object System.Windows.Forms.Label
  $lblPat.Text = '访问令牌(PAT)：'
  $lblPat.Location = New-Object System.Drawing.Point(16, 152)
  $lblPat.Size = New-Object System.Drawing.Size(120, 22)

  $txtPat = New-Object System.Windows.Forms.TextBox
  $txtPat.Location = New-Object System.Drawing.Point(142, 148)
  $txtPat.Size = New-Object System.Drawing.Size(330, 24)
  $txtPat.PasswordChar = '*'

  $btnOk = New-Object System.Windows.Forms.Button
  $btnOk.Text = '保存并继续'
  $btnOk.Location = New-Object System.Drawing.Point(350, 198)
  $btnOk.Size = New-Object System.Drawing.Size(96, 32)
  $btnOk.DialogResult = 'OK'

  $btnSkip = New-Object System.Windows.Forms.Button
  $btnSkip.Text = '跳过'
  $btnSkip.Location = New-Object System.Drawing.Point(452, 198)
  $btnSkip.Size = New-Object System.Drawing.Size(92, 32)
  $btnSkip.DialogResult = 'Cancel'

  $null = $form.Controls.Add($lblTip)
  $null = $form.Controls.Add($lblUser); $null = $form.Controls.Add($txtUser)
  $null = $form.Controls.Add($lblPat);  $null = $form.Controls.Add($txtPat)
  $null = $form.Controls.Add($btnOk);   $null = $form.Controls.Add($btnSkip)
  $form.AcceptButton = $btnOk
  $form.CancelButton = $btnSkip

  $form.Add_Shown({ $txtUser.Focus() })
  if ($form.ShowDialog() -eq [System.Windows.Forms.DialogResult]::OK) {
    $u = $txtUser.Text.Trim()
    $p = $txtPat.Text.Trim()
    if ($u -and $p) { return [pscustomobject]@{ User = $u; Pat = $p } }
  }
  return $null
}

# ---------- 主流程 ----------
try {
  Log '========== 博客管家 安装开始 =========='
  New-Item -ItemType Directory -Force $Install | Out-Null

  # 1) PHP 运行时
  if (Test-Path (Join-Path $Payload 'php\php.exe')) {
    Copy-Item -Recurse -Force (Join-Path $Payload 'php') $PhpDir
    Log 'PHP 运行时已就位'
  } else { Die '安装包缺少 PHP 运行时，请重新下载安装包' }

  # 2) 便携版 git（先检查载荷里的，再拷到安装目录）
  if (Test-Path (Join-Path $Payload 'git\cmd\git.exe')) {
    Copy-Item -Recurse -Force (Join-Path $Payload 'git') $GitDir
    Log '便携版 git 已就位'
  } else { Die '安装包缺少 git，请重新下载安装包' }

  # 3) 站点内容：检查 + 自动拉取
  if (Test-Path $SiteDir) {
    if (Test-Path (Join-Path $SiteDir '.git')) {
      Log '检测到已有站点，正在拉取更新…'
      $r = Run-Capture $GitExe @('pull', '--rebase', '--autostash') $SiteDir
      if ($r.Code -ne 0) { Die "拉取更新失败：$($r.Err.Trim())" }
    } else {
      Die '站点目录已存在但不是 git 仓库。请手动删除该目录后重装，或改用其他安装位置。'
    }
  } else {
    Log '未发现站点内容，正在从 GitHub 克隆最新内容…'
    $r = Run-Capture $GitExe @('clone', $RepoUrl, $SiteDir) $Install
    if ($r.Code -ne 0) { Die "无法连接 GitHub 拉取内容：$($r.Err.Trim())" }
  }
  Log '站点内容已同步到最新'

  # 4) GitHub 登录（发布用）
  if (-not $TestMode) {
    $creds = Get-GitCreds
    if ($creds) {
      $credFile = Join-Path $env:USERPROFILE '.git-credentials'
      $keep = @()
      if (Test-Path $credFile) { $keep = @(Get-Content -LiteralPath $credFile | Where-Object { $_ -notmatch '@github\.com' }) }
      @($keep) + "https://$($creds.User):$($creds.Pat)@github.com" |
        Set-Content -LiteralPath $credFile -Encoding ASCII
      Run-Capture $GitExe @('config', 'credential.helper', 'store') $SiteDir | Out-Null
      Run-Capture $GitExe @('config', 'user.name', $creds.User) $SiteDir | Out-Null
      Run-Capture $GitExe @('config', 'user.email', "$($creds.User)@users.noreply.github.com") $SiteDir | Out-Null
      Log 'GitHub 凭据已保存（发布将全自动）'
    } else {
      Log '已跳过 GitHub 登录（之后发布前需重新运行安装包补齐令牌）'
    }
  } else {
    Log '测试模式：跳过 GitHub 登录'
  }

  # 5) config.json
  New-Item -ItemType Directory -Force $DeskDir | Out-Null
  $cfg = [ordered]@{
    phpPath   = ($PhpExe  -replace '\\', '/')
    siteDir   = ($SiteDir -replace '\\', '/')
    port      = 8000
    autoBuild = $true
    autoPush  = $false
    gitPath   = ($GitExe  -replace '\\', '/')
  }
  $cfg | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $DeskDir 'config.json') -Encoding UTF8
  Log 'config.json 已生成'

  # 6) 桌面快捷方式
  if (-not $TestMode) {
    $lnkPath = Join-Path ([Environment]::GetFolderPath('Desktop')) '博客管家.lnk'
    $ws = New-Object -ComObject WScript.Shell
    $sc = $ws.CreateShortcut($lnkPath)
    $sc.TargetPath = Join-Path $DeskDir 'launcher.vbs'
    $sc.WorkingDirectory = $DeskDir
    $sc.Description = '博客管家：启动本地博客服务到托盘'
    $sc.Save()
    Log '桌面快捷方式已创建'
  } else {
    Log '测试模式：跳过桌面快捷方式'
  }

  # 7) 启动托盘应用（不阻塞，托盘气泡会提示就绪）
  if (-not $TestMode) {
    Start-Process wscript.exe -ArgumentList ('"' + (Join-Path $DeskDir 'launcher.vbs') + '"')
    Log '托盘应用已启动'
  } else {
    Log '测试模式：跳过托盘启动'
  }
  Log '========== 安装完成 =========='
} catch {
  Die "安装过程出错：$_"
}
