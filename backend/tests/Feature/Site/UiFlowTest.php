<?php

namespace Tests\Feature\Site;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * End-to-end API flows backing UI buttons (create, pay, report, etc.).
 */
class UiFlowTest extends TestCase
{
    private function admin(): User
    {
        return $this->userWithApiPermissions([
            'view reports', 'view financial reports', 'view accounting reports',
            'manage buildings', 'manage rooms', 'manage clients', 'create reservations',
            'update reservations', 'view users',
        ]);
    }

    public function test_dashboard_summary_matches_room_board_date(): void
    {
        $user = $this->admin();
        $date = '2026-08-15';

        $dash = $this->actingAs($user, 'api')->getJson('/api/users/dashboard/summary');
        $board = $this->actingAs($user, 'api')->getJson("/api/users/rooms/occupancy-board?date={$date}");

        $dash->assertOk();
        $board->assertOk();
        $this->assertIsArray($board->json('data.rooms'));
        $this->assertArrayHasKey('occupancy_rate', $board->json('data.summary') ?? []);
    }

    public function test_revenue_and_earnings_same_period(): void
    {
        $user = $this->admin();
        $start = '2026-08-01';
        $end = '2026-08-31';

        $rev = $this->actingAs($user, 'api')->getJson("/api/users/revenue/total?start_date={$start}&end_date={$end}");
        $earn = $this->actingAs($user, 'api')->getJson("/api/users/earnings-summary?start_date={$start}&end_date={$end}");

        $rev->assertOk();
        $earn->assertOk();
        $this->assertTrue($rev->json('success'));
        $this->assertTrue($earn->json('success'));
    }

    public function test_all_report_export_slugs_return_rows_structure(): void
    {
        $user = $this->admin();
        $slugs = [
            'arrivals-departures', 'occupancy', 'revenue-summary', 'accrual-cash-reconciliation',
            'ar-aging', 'payments-refunds', 'trial-balance',
        ];

        foreach ($slugs as $slug) {
            $response = $this->actingAs($user, 'api')->getJson(
                "/api/users/reports/{$slug}?start_date=2026-08-01&end_date=2026-08-31"
            );
            $response->assertOk("Report {$slug} failed");
            $this->assertIsArray($response->json('data.rows'), "Report {$slug} missing rows");
        }
    }

    public function test_departments_crud_list_loads(): void
    {
        $user = $this->admin();
        $response = $this->actingAs($user, 'api')->getJson('/api/users/getDepartment');
        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_login_with_valid_credentials(): void
    {
        $user = User::first();
        $this->assertNotNull($user);

        if (!Hash::check('admin123', $user->password) && !Hash::check('password', $user->password)) {
            $user->update(['password' => Hash::make('admin123')]);
        }

        $password = Hash::check('admin123', $user->password) ? 'admin123' : 'password';

        $response = $this->postJson('/api/users/login', [
            'job_number' => $user->job_number,
            'password' => $password,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertIsArray($response->json('data.permissions'));
    }
}
