<?php

namespace Tests\Feature\Reservation;

use App\Models\Client;
use App\Models\ReservationChangeLog;
use App\Models\Reservation_source;
use App\Models\Room;
use App\Models\Stay_reason;
use App\Services\ReservationRoomStatusService;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class ReservationChangeLogTest extends TestCase
{
    public function test_update_reservation_records_old_values_and_user(): void
    {
        Carbon::setTestNow('2026-06-15');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = $this->userWithApiPermissions();
        $client = Client::first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        $room = $this->findOrCreateAvailableRoom('2026-06-15', '2026-06-18');

        $create = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', [
            'client_id' => $client->id,
            'rooms' => [['room_id' => $room->id]],
            'start_date' => '2026-06-15',
            'expire_date' => '2026-06-18',
            'reservation_type' => 0,
            'reservation_status' => 1,
            'stay_reason_id' => $stayReason->id,
            'reservation_source_id' => $source->id,
            'rent_type' => 0,
            'price_calculation_mode' => 0,
            'discount' => 0,
            'extras' => 0,
            'penalties' => 0,
            'pay_amount' => 0,
            'logedin' => 0,
        ]);
        $create->assertOk();

        $reservationId = $create->json('data.reservation.id') ?? $create->json('data.id');
        $this->assertNotNull($reservationId);

        Room::where('id', $room->id)->update(['roomStatus' => ReservationRoomStatusService::ROOM_AVAILABLE]);

        $patch = $this->actingAs($user, 'api')->patchJson("/api/users/reservations/{$reservationId}", [
            'logedin' => 1,
            'login_time' => '2026-06-15',
        ]);
        $patch->assertOk();

        $log = ReservationChangeLog::where('reservation_id', $reservationId)
            ->where('action', 'checked_in')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(0, $log->changes['logedin']['old'] ?? null);
        $this->assertSame(1, $log->changes['logedin']['new'] ?? null);

        $show = $this->actingAs($user, 'api')->getJson("/api/users/reservations/{$reservationId}");
        $show->assertOk();
        $changeLogs = $show->json('data.change_logs') ?? [];
        $this->assertNotEmpty($changeLogs);
        $this->assertSame($user->id, $changeLogs[0]['user']['id'] ?? null);
        $this->assertSame($user->job_number, $changeLogs[0]['user']['job_number'] ?? null);

        Carbon::setTestNow();
    }

    public function test_date_update_records_previous_dates(): void
    {
        Carbon::setTestNow('2026-06-15');

        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $user = $this->userWithApiPermissions();
        $client = Client::first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        $room = $this->findOrCreateAvailableRoom('2026-06-15', '2026-06-18');

        $create = $this->actingAs($user, 'api')->postJson('/api/users/makeReservation', [
            'client_id' => $client->id,
            'rooms' => [['room_id' => $room->id]],
            'start_date' => '2026-06-15',
            'expire_date' => '2026-06-18',
            'reservation_type' => 0,
            'reservation_status' => 1,
            'stay_reason_id' => $stayReason->id,
            'reservation_source_id' => $source->id,
            'rent_type' => 0,
            'price_calculation_mode' => 0,
            'discount' => 0,
            'extras' => 0,
            'penalties' => 0,
            'pay_amount' => 0,
            'logedin' => 0,
        ]);
        $create->assertOk();

        $reservationId = $create->json('data.reservation.id') ?? $create->json('data.id');

        $patch = $this->actingAs($user, 'api')->patchJson("/api/users/reservations/{$reservationId}", [
            'expire_date' => '2026-06-20',
        ]);
        $patch->assertOk();

        $log = ReservationChangeLog::where('reservation_id', $reservationId)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('2026-06-18', $log->changes['expire_date']['old'] ?? null);
        $this->assertSame('2026-06-20', $log->changes['expire_date']['new'] ?? null);

        Carbon::setTestNow();
    }
}
