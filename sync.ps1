# 一麦工作台 · 一键同步脚本（自动生成变更日志）
# 用法：
#   .\sync.ps1                          # 交互式输入提交说明
#   .\sync.ps1 -m "feat: 新增xxx"       # 带说明直接提交并推送
param([string]$m = "")

$ErrorActionPreference = "Stop"
Set-Location (Split-Path $MyInvocation.MyCommand.Path)

if (-not $m) {
  $m = Read-Host "提交说明（如 feat: 新增会员导出）"
}
if (-not $m.Trim()) { Write-Host "✗ 提交说明不能为空" -ForegroundColor Red; exit 1 }

Write-Host "`n[1/5] 暂存变更..." -ForegroundColor Cyan
git add -A

$status = git status --porcelain
if (-not $status) {
  Write-Host "✓ 没有需要提交的变更" -ForegroundColor Green
  exit 0
}

Write-Host "[2/5] 生成变更清单到 CHANGELOG.md..." -ForegroundColor Cyan
$stamp = Get-Date -Format "yyyy-MM-dd HH:mm"
$lines = git status --porcelain
$items = foreach ($l in $lines) {
  $code = $l.Substring(0, 2).Trim()
  $file = $l.Substring(3).Trim('"')
  $tag = switch ($code) { "A" { "新增" } "D" { "删除" } "R" { "重命名" } default { "修改" } }
  "- $tag $file"
}
$fileList = $items | Select-Object -First 30
if ($items.Count -gt 30) { $fileList += "- …等共 $($items.Count) 个文件" }
$entry = "## $stamp ｜ $m`n`n" + ($fileList -join "`n") + "`n`n"

$cl = "CHANGELOG.md"
if (Test-Path $cl) {
  $allLines = Get-Content $cl -Encoding UTF8
  if ($allLines.Count -gt 0 -and $allLines[0] -like "# *") {
    $head = $allLines[0]
    $rest = if ($allLines.Count -gt 1) { $allLines[1..($allLines.Count - 1)] -join "`n" } else { "" }
    Set-Content -Path $cl -Value ($head + "`n`n" + $entry + $rest) -Encoding UTF8 -NoNewline
  } else {
    Set-Content -Path $cl -Value ("# 更新日志`n`n" + $entry + ($allLines -join "`n")) -Encoding UTF8 -NoNewline
  }
} else {
  Set-Content -Path $cl -Value ("# 更新日志`n`n" + $entry) -Encoding UTF8 -NoNewline
}
git add CHANGELOG.md

Write-Host "[3/5] 提交..." -ForegroundColor Cyan
git commit -m "$m"
if ($LASTEXITCODE -ne 0) { Write-Host "✗ 提交失败" -ForegroundColor Red; exit 1 }

Write-Host "[3.5/5] 推送 Gitee（主仓库 / 权威源）..." -ForegroundColor Cyan
# Gitee 国内直连稳定；它是权威源，推不上去必须报错（不能只推 GitHub）
git push https://gitee.com/meng-taoo/yimai-workbench.git main:main
if ($LASTEXITCODE -ne 0) {
  Write-Host "✗ Gitee 推送失败（权威源不可跳过）" -ForegroundColor Red
  exit 1
}

Write-Host "[4/5] 推送 GitHub 镜像（github.shunan.fun 反代通道）..." -ForegroundColor Cyan
# ⚠ 镜像推送三要素（沉淀自一麦GOS 排障手册，2026-10-26）：
#   1) -c http.proxy=  置空本会话代理，绕开全局坏代理（http.proxy 可能指向死端口）
#   2) 显式写镜像 URL，避免 insteadOf 二次重写的干扰
#   3) 反代通道（Cloudflare）间歇抖动：连挂时等 20 秒重试，三连挂后降级 HTTP/1.1 + 大 postBuffer
$mirrorUrl = "https://github.shunan.fun/a6828464/yimai-workbench.git"
$mirrorOk = $false
for ($attempt = 1; $attempt -le 3; $attempt++) {
  git -c http.proxy= push $mirrorUrl main:main
  if ($LASTEXITCODE -eq 0) { $mirrorOk = $true; break }
  Write-Host "  第 $attempt 次失败，等 20 秒重试（镜像通道间歇抖动属正常）..." -ForegroundColor Yellow
  Start-Sleep -Seconds 20
}
if (-not $mirrorOk) {
  Write-Host "  降级 HTTP/1.1 + 500MB postBuffer 再试一次..." -ForegroundColor Yellow
  git -c http.proxy= -c http.version=HTTP/1.1 -c http.postBuffer=524288000 push $mirrorUrl main:main
  if ($LASTEXITCODE -eq 0) { $mirrorOk = $true }
}

Write-Host "[5/5] 验证双端 SHA 一致..." -ForegroundColor Cyan
$giteeSha = (git ls-remote https://gitee.com/meng-taoo/yimai-workbench.git HEAD) -split "`t" | Select-Object -First 1
$mirrorSha = (git -c http.proxy= ls-remote $mirrorUrl HEAD) -split "`t" | Select-Object -First 1
$localSha = git rev-parse HEAD
Write-Host "  本地   : $($localSha.Substring(0,12))"
Write-Host "  Gitee  : $($giteeSha.Substring(0,12))"
Write-Host "  GitHub : $($mirrorSha.Substring(0,12))"

if ($LASTEXITCODE -eq 0 -and $mirrorOk -and $giteeSha -eq $localSha -and $mirrorSha -eq $localSha) {
  Write-Host "`n✓ 双平台同步完成 · SHA 一致 · 在线查看: https://github.com/a6828464/yimai-workbench/blob/main/CHANGELOG.md" -ForegroundColor Green
} else {
  Write-Host "`n⚠ 推送未完全成功：Gitee=$($giteeSha -ne '') GitHub=$mirrorOk，稍后重试 git push origin main" -ForegroundColor Yellow
  if (-not $mirrorOk) {
    Write-Host "  GitHub 镜像失败可单独重试：git -c http.proxy= push $mirrorUrl main:main" -ForegroundColor Yellow
  }
}
