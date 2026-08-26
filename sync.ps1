# 一麦工作台 · 一键同步脚本
# 用法：
#   .\sync.ps1                          # 交互式输入提交说明
#   .\sync.ps1 -m "feat: 新增xxx"       # 带说明直接提交并推送
param([string]$m = "")

$ErrorActionPreference = "Stop"
Set-Location $PSScriptType = $PSScriptRoot
Set-Location (Split-Path $MyInvocation.MyCommand.Path)

if (-not $m) {
  $m = Read-Host "提交说明（如 feat: 新增会员导出）"
}
if (-not $m.Trim()) { Write-Host "✗ 提交说明不能为空" -ForegroundColor Red; exit 1 }

Write-Host "`n[1/3] 暂存变更..." -ForegroundColor Cyan
git add -A

$status = git status --porcelain
if (-not $status) {
  Write-Host "✓ 没有需要提交的变更" -ForegroundColor Green
  exit 0
}

Write-Host "[2/3] 提交..." -ForegroundColor Cyan
git commit -m "$m"
if ($LASTEXITCODE -ne 0) { Write-Host "✗ 提交失败" -ForegroundColor Red; exit 1 }

Write-Host "[3/3] 推送到 GitHub..." -ForegroundColor Cyan
git push origin master
if ($LASTEXITCODE -eq 0) {
  Write-Host "`n✓ 已同步到线上仓库" -ForegroundColor Green
  git log --oneline -1
} else {
  Write-Host "`n⚠ 推送失败（网络波动时重试一次；或检查令牌是否过期）" -ForegroundColor Yellow
  Write-Host "  重试：git push origin master" -ForegroundColor Yellow
}
