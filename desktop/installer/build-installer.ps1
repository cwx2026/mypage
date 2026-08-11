#requires -Version 5.1
<#
  博客管家 安装包构建脚本（C# 自解压方案）
  - 载荷：精简 PHP 运行时 + 便携版 MinGit + setup.ps1 → 打成 payload.zip
  - 用 Windows 自带的 .NET csc.exe 把 zip 作为嵌入资源编进一个极小的 C# 自解压程序
  - 得到单文件 exe：双击 → 解压到临时目录 → 运行 setup.ps1 完成安装
  优点：零外部工具、无需管理员权限、纯 PE 可执行文件。
  用法：powershell -NoProfile -ExecutionPolicy Bypass -File build-installer.ps1
  产物：dist\博客管家安装.exe（不携带内容，安装时总从 GitHub 拉最新）
#>
param(
  [string]$PhpRoot   = 'D:/php',
  [string]$MinGitZip = 'dist/stage/mingit/MinGit.zip'
)
$ErrorActionPreference = 'Stop'

$Repo    = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)   # desktop/installer -> 仓库根
$Stage   = Join-Path $Repo 'dist\stage'
$Payload = Join-Path $Stage 'payload'
$ZipPath = Join-Path $Stage 'payload.zip'
$OutExe  = Join-Path $Repo 'dist\博客管家安装.exe'
$Csc     = "$env:WINDIR\Microsoft.NET\Framework64\v4.0.30319\csc.exe"

if (-not [System.IO.Path]::IsPathRooted($MinGitZip)) { $MinGitZip = Join-Path $Repo $MinGitZip }
if (-not (Test-Path $Csc)) { throw "缺少 .NET 编译器：$Csc" }

Write-Host '== 博客管家 安装包构建（C# 自解压）=='

# ---------- 1. 清理 + 建目录 ----------
Remove-Item -Recurse -Force $Payload -ErrorAction SilentlyContinue
Remove-Item -Force $ZipPath -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force (Join-Path $Payload 'php\ext') | Out-Null
New-Item -ItemType Directory -Force (Join-Path $Payload 'git')     | Out-Null

# ---------- 2. 精简 PHP 运行时 ----------
foreach ($f in @('php.exe', 'php8ts.dll', 'php.ini')) {
  if (-not (Test-Path (Join-Path $PhpRoot $f))) { throw "缺少 PHP 文件: $f" }
  Copy-Item (Join-Path $PhpRoot $f) (Join-Path $Payload 'php')
}
foreach ($e in @('php_fileinfo.dll', 'php_gd.dll', 'php_mbstring.dll')) {
  if (-not (Test-Path (Join-Path $PhpRoot "ext\$e"))) { throw "缺少扩展: $e" }
  Copy-Item (Join-Path $PhpRoot "ext\$e") (Join-Path $Payload 'php\ext')
}
foreach ($vc in @('vcruntime140.dll', 'vcruntime140_1.dll', 'msvcp140.dll')) {
  if (Test-Path "C:\Windows\System32\$vc") { Copy-Item "C:\Windows\System32\$vc" (Join-Path $Payload 'php') }
}
Write-Host '  PHP 运行时已组装'

# ---------- 3. MinGit ----------
if (-not (Test-Path $MinGitZip)) { throw "找不到 MinGit 压缩包: $MinGitZip" }
$mingitX = Join-Path $Stage 'mingit-x'
Remove-Item -Recurse -Force $mingitX -ErrorAction SilentlyContinue
Expand-Archive -LiteralPath $MinGitZip -DestinationPath $mingitX -Force
Copy-Item -Recurse -Force (Join-Path $mingitX '*') (Join-Path $Payload 'git')
Write-Host '  便携版 git 已组装'

# ---------- 4. 拷贝 setup.ps1 ----------
Copy-Item (Join-Path $PSScriptRoot 'setup.ps1') $Payload

# ---------- 5. 打成 payload.zip ----------
Write-Host '  打包载荷…'
Compress-Archive -Path (Join-Path $Payload '*') -DestinationPath $ZipPath -CompressionLevel Optimal -Force
Write-Host ("  载荷压缩完成（{0} MB）" -f [math]::Round((Get-Item $ZipPath).Length / 1MB, 1))

# ---------- 6. C# 自解压器源码 ----------
$Cs = @'
using System;
using System.Diagnostics;
using System.IO;
using System.IO.Compression;
using System.Reflection;
using System.Threading;
using System.Windows.Forms;

class BlogManagerInstaller
{
    [STAThread]
    static int Main()
    {
        string mutexName = "BlogManagerSetup_SingleInstance";
        bool isNew;
        using (var mutex = new Mutex(true, mutexName, out isNew))
        {
            if (!isNew) return 0; // 已有安装程序在运行
            string temp = Path.Combine(Path.GetTempPath(), "BlogManagerSetup", Guid.NewGuid().ToString("N"));
            try
            {
                Directory.CreateDirectory(temp);
                string zip = Path.Combine(temp, "payload.zip");
                using (var res = Assembly.GetExecutingAssembly().GetManifestResourceStream("BlogSetup.payload.zip"))
                using (var f = File.Create(zip))
                {
                    if (res == null) throw new Exception("安装包损坏：缺少载荷。");
                    res.CopyTo(f);
                }
                ZipFile.ExtractToDirectory(zip, temp);
                string ps = Path.Combine(temp, "setup.ps1");
                ProcessStartInfo psi = new ProcessStartInfo();
                psi.FileName = "powershell.exe";
                psi.Arguments = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File \"" + ps + "\"";
                psi.WorkingDirectory = temp;
                psi.UseShellExecute = false;
                psi.CreateNoWindow = true;
                Process p = Process.Start(psi);
                p.WaitForExit(); // 等 setup 完成（含用户填 PAT 的时间）
            }
            catch (Exception ex)
            {
                try { MessageBox.Show("安装失败：" + ex.Message, "博客管家", MessageBoxButtons.OK, MessageBoxIcon.Error); } catch { }
            }
            finally
            {
                try { Thread.Sleep(1500); if (Directory.Exists(temp)) Directory.Delete(temp, true); } catch { }
            }
        }
        return 0;
    }
}
'@
$CsPath = Join-Path $Stage 'SFXLauncher.cs'
$Cs | Set-Content -LiteralPath $CsPath -Encoding UTF8

# ---------- 7. 编译 ----------
Write-Host '  编译自解压安装器…'
$args = @(
  '/nologo', '/target:winexe',
  "/out:$OutExe",
  "/resource:$ZipPath,BlogSetup.payload.zip",
  '/r:System.Windows.Forms.dll',
  '/r:System.IO.Compression.dll',
  '/r:System.IO.Compression.FileSystem.dll',
  $CsPath
)
& $Csc @args
if ($LASTEXITCODE -ne 0) { throw '编译失败' }
if (-not (Test-Path $OutExe)) { throw '未产出安装包' }

Write-Host ("  构建完成：{0}（{1} MB）" -f $OutExe, [math]::Round((Get-Item $OutExe).Length / 1MB, 1))
