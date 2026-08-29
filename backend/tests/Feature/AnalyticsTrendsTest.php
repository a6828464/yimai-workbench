<?php

namespace Tests\Feature;

use App\Models\KyBooking;
use App\Models\Lead;
use App\Models\User;
use App\Services\KyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsTrendsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_scopes_summary_and_uses_keepyoga_trial_booking_facts(): void
    {
        Sanctum::actingAs(User::factory()->create(['username' => 'analytics-test', 'role' => 'R_SUPER']));

        Lead::create([
            'lead_date' => '2026-08-29',
            'name' => 'CRM体验客',
            'venue' => '绿地店',
            'status' => '已体验',
        ]);
        $this->booking('green-booked', '绿地店', '13800000001', 'booked');
        $this->booking('green-signed', '绿地店', '13800000002', 'signed');
        $this->booking('east-cancelled', '东部店', '13800000003', 'cancelled');

        $this->getJson('/api/analytics/trends?start=2026-08-29&end=2026-08-29&venue='.urlencode('绿地店'))
            ->assertOk()
            ->assertJsonPath('data.summary.bookingCount', 2)
            ->assertJsonPath('data.summary.trialCount', 1)
            ->assertJsonPath('data.summary.visitCount', 1);

        $this->getJson('/api/analytics/trends?start=2026-08-29&end=2026-08-29&venue='.urlencode('东部店'))
            ->assertOk()
            ->assertJsonPath('data.summary.bookingCount', 0)
            ->assertJsonPath('data.summary.trialCount', 0);

        config(['services.ky.phone' => '13800000000', 'services.ky.password' => 'secret']);
        Cache::put('ky_access_token', 'token');
        Http::fake([
            KyClient::BASE.'/member/api/getvisitors' => Http::response(['errno' => 0, 'data' => ['total' => 3]]),
        ]);

        $response = KyClient::call('/member/api/getvisitors', ['venue_id' => '1']);

        $this->assertSame(3, $response['data']['total']);
        Http::assertSent(fn ($request) => $request->url() === KyClient::BASE.'/member/api/getvisitors');
    }

    private function booking(string $key, string $venue, string $phone, string $status): void
    {
        KyBooking::create([
            'source_key' => $key,
            'venue' => $venue,
            'booking_type' => '团课',
            'member_name' => '新客体验',
            'phone' => $phone,
            'start_at' => '2026-08-29 10:00:00',
            'course_name' => '体验课',
            'status_raw' => $status,
            'status' => $status,
            'is_trial' => true,
        ]);
    }
}
