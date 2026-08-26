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

Write-Host "`n[1/4] 暂存变更..." -ForegroundColor Cyan
git add -A

$status = git status --porcelain
if (-not $status) {
  Write-Host "✓ 没有需要提交的变更" -ForegroundColor Green
  exit 0
}

Write-Host "[2/4] 生成变更清单到 CHANGELOG.md..." -ForegroundColor Cyan
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

Write-Host "[3/4] 提交..." -ForegroundColor Cyan
git commit -m "$m"
if ($LASTEXITCODE -ne 0) { Write-Host "✗ 提交失败" -ForegroundColor Red; exit 1 }

Write-Host "[4/4] 推送到 GitHub..." -ForegroundColor Cyan
git push origin main
if ($LASTEXITCODE -eq 0) {
  Write-Host "`n✓ 已同步 · CHANGELOG.md 已更新 · 在线查看: https://github.com/a6828464/yimai-workbench/blob/master/CHANGELOG.md" -ForegroundColor Green
} else {
  Write-Host "`n⚠ 推送失败，稍后重试: git push origin main" -ForegroundColor Yellow
}
