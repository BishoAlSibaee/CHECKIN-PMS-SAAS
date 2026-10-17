<?php

namespace Tests\Feature\Security;

use App\Models\Room;
use App\Models\User;
use Tests\TestCase;

class ProtectedRoutesTest extends TestCase
{
    public function test_buildings_requires_authentication(): void
    {
        $this->getJson('/api/users/buildings')->assertStatus(401);
    }

    public function test_reservations_show_requires_authentication(): void
    {
        $this->getJson('/api/users/reservations/1')->assertStatus(401);
    }

    public function test_report_export_download_rejects_invalid_token(): void
    {
        $user = User::first();
        $this->assertNotNull($user);

        $export = \App\Models\ReportExport::create([
            'user_id' => $user->id,
            'slug' => 'occupancy',
            'file_format' => \App\Models\ReportExport::FORMAT_EXCEL,
            'recipient_email' => 'ops@hotel.test',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-07',
            'status' => \App\Models\ReportExport::STATUS_READY,
            'file_path' => 'reports/missing.xlsx',
            'download_token' => \App\Models\ReportExport::generateToken(),
            'expires_at' => now()->addDay(),
        ]);

        $this->getJson('/api/users/reports/exports/' . $export->id . '/download?token=invalid')
            ->assertStatus(403);

        $export->delete();
    }

    public function test_report_export_download_requires_token_even_for_owner(): void
    {
        $user = User::first();
        $this->assertNotNull($user);

        $export = \App\Models\ReportExport::create([
            'user_id' => $user->id,
            'slug' => 'occupancy',
            'file_format' => \App\Models\ReportExport::FORMAT_EXCEL,
            'recipient_email' => 'ops@hotel.test',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-07',
            'status' => \App\Models\ReportExport::STATUS_READY,
            'file_path' => 'reports/missing.xlsx',
            'download_token' => \App\Models\ReportExport::generateToken(),
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/users/reports/exports/' . $export->id . '/download')
            ->assertStatus(403);

        $export->delete();
    }

    public function test_add_building_forbidden_without_manage_buildings_permission(): void
    {
        $user = $this->userWithOnlyPermissions(['view buildings']);

        $response = $this->actingAs($user, 'api')->postJson('/api/users/building', [
            'name' => 'Blocked Tower',
            'number' => 9999,
            'numberOfFloor' => 1,
            'numberFloor' => 1,
        ]);

        $response->assertStatus(403);
    }

    public function test_add_suite_forbidden_without_manage_suites_permission(): void
    {
        $user = $this->userWithOnlyPermissions(['view suites', 'view rooms']);
        $room = Room::whereNull('suite_id')->where('active', 1)->first();

        if (!$room) {
            $this->markTestSkipped('No unassigned room available for suite test.');
        }

        $response = $this->actingAs($user, 'api')->postJson('/api/users/addSuite', [
            'building_id' => $room->building_id,
            'floor_id' => $room->floor_id,
            'number' => 'Test Suite ' . uniqid(),
            'rooms' => [['id' => $room->id]],
        ]);

        $response->assertStatus(403);
    }

    public function test_add_suite_succeeds_with_manage_suites_permission(): void
    {
        $user = $this->userWithOnlyPermissions([
            'view suites', 'manage suites', 'view rooms', 'view buildings', 'view floors',
        ]);
        $room = Room::whereNull('suite_id')->where('active', 1)->first();

        if (!$room) {
            $this->markTestSkipped('No unassigned room available for suite test.');
        }

        $suiteNumber = 'Suite-' . uniqid();

        $response = $this->actingAs($user, 'api')->postJson('/api/users/addSuite', [
            'building_id' => $room->building_id,
            'floor_id' => $room->floor_id,
            'number' => $suiteNumber,
            'rooms' => [['id' => $room->id]],
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('suites', [
            'number' => $suiteNumber,
            'building_id' => $room->building_id,
            'floor_id' => $room->floor_id,
        ]);
    }
}
