<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {
    App\Models\AppSetting::create(['rules' => ['renewalThreshold' => 10, 'vipThreshold' => 100, 'declineMode' => 'strict']]);
    echo 'APPSETTING OK, rows='.App\Models\AppSetting::count().PHP_EOL;
} catch (Throwable $e) { echo 'FAIL: '.substr($e->getMessage(),0,100).PHP_EOL; }