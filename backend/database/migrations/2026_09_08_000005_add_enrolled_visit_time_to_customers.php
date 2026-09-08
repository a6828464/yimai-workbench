<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 会员入会/到访时间：来自 KeepYoga 会员基础表 create_time_format(入会)、visit_time_format(到访)。
     * enrolled_at 非空即「正式入会办卡会员」，是新客培养栏目判定新入会的锚点。
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->date('enrolled_at')->nullable()->after('last_visit')->index();
            $t->date('visit_at')->nullable()->after('enrolled_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $t) {
            $t->dropColumn(['enrolled_at', 'visit_at']);
        });
    }
};
