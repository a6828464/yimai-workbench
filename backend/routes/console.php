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
