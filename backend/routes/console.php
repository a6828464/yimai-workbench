<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('records:prune', function () {
    $this->info(json_encode(pruneSystemRecords(), JSON_UNESCAPED_UNICODE));
})->purpose('Prune system logs and model/audit records using configured retention');

Schedule::command('records:prune')->dailyAt('03:30')->withoutOverlapping();

// KeepYoga 双店定时增量同步（幂等：当天已同步的门店自动跳过；与手动导入共用全局锁互斥）
Schedule::command('ky:autosync')->dailyAt('05:30')->withoutOverlapping();

// 数据备份：每 10 分钟触发一次，由命令内部按配置的 run_at（默认 03:30）判断窗口并保证每天只跑一次
Schedule::command('backup:run')->everyTenMinutes()->withoutOverlapping();
