<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NotificationController extends Controller
{
    /** GET /notifications */
    public function index(Request $r)
    {
        $items = businessNotifications($r->user());
        $readKeys = DB::table('notification_reads')->where('user_id', $r->user()->id)->pluck('notification_key')->all();
        $items = collect($items)->map(fn ($item) => $item + ['read' => in_array($item['key'], $readKeys, true)])->values();

        return ok(['items' => $items, 'unreadCount' => $items->where('read', false)->count(), 'refreshedAt' => now()->format('Y-m-d H:i:s')]);
    }

    /** POST /notifications/read-all */
    public function readAll(Request $r)
    {
        $now = now();
        foreach (businessNotifications($r->user()) as $item) {
            DB::table('notification_reads')->updateOrInsert(
                ['user_id' => $r->user()->id, 'notification_key' => $item['key']],
                ['read_at' => $now]
            );
        }

        return ok(['read' => true]);
    }

    /** PATCH /notifications/{key}/read */
    public function readOne(Request $r, string $key)
    {
        abort_unless(collect(businessNotifications($r->user()))->contains('key', $key), 404);
        DB::table('notification_reads')->updateOrInsert(
            ['user_id' => $r->user()->id, 'notification_key' => $key],
            ['read_at' => now()]
        );

        return ok(['read' => true]);
    }
}
