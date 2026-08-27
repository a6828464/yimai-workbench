#!/bin/bash
# 一麦工作台 · 本地开发一键启动（Mac 版，对应 Windows 的手动流程）
# 用法: ./dev.sh          启动前后端
#       ./dev.sh stop     停止全部
ROOT="$(cd "$(dirname "$0")" && pwd)"

if [ "$1" = "stop" ]; then
  screen -S yimai-backend -X quit 2>/dev/null
  screen -S yimai-frontend -X quit 2>/dev/null
  echo "已停止"
  exit 0
fi

screen -S yimai-backend  -X quit 2>/dev/null
screen -S yimai-frontend -X quit 2>/dev/null

screen -dmS yimai-backend /bin/bash -c "cd '$ROOT/backend' && exec php artisan serve --host=127.0.0.1 --port=8000"
screen -dmS yimai-frontend /bin/bash -c "cd '$ROOT/admin-web' && exec pnpm dev"

sleep 5
echo "前端: http://localhost:3006"
echo "后端: http://127.0.0.1:8000"
echo "查看日志: screen -r yimai-backend / yimai-frontend   (退出按 Ctrl+A 再按 D)"
