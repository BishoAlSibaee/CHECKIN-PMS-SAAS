<?php

namespace Tests\Feature\Grms;

use App\Exceptions\RefundNotAllowedException;
use App\Models\Client;
use App\Models\GrmsOutboxEvent;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\ReservationRoom;
use App\Models\Stay_reason;
use App\Services\RefundPolicyService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * No-show: a manual action for a direct-booking reservation whose check-in
 * date has passed without the guest ever checking in. Mechanically a
 * cancellation, with two differences: no refund ever, and GRMS gets
 * status "no_show" instead of "cancelled" (docs/pms-contract.md section 5).
 */
class NoShowTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    private function makePastConfirmedReservation(bool $isOta = false, bool $checkedIn = false): array
    {
        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\EssentialReferenceDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $client = Client::create([
            'first_name' => 'No', 'last_name' => 'Show'.$this->uniqueSuffix(),
            'international_code' => '+966', 'mobile' => '05'.substr($this->uniqueSuffix(), -8),
            'IdType' => 'PASSPORT', 'IdNumber' => 'TEST-'.$this->uniqueSuffix(),
            'gender' => 'MALE', 'guest_type' => 'VISITOR',
        ]);
        $room = $this->findOrCreateAvailableRoom('2026-08-01', '2026-08-03');
        $stayReason = Stay_reason::first();
        $source = Reservation_source::query()->updateOrCreate(
            ['name_en' => $isOta ? 'Test OTA' : 'Direct'],
            ['name_ar' => $isOta ? 'قناة تجريبية' : 'مباشر', 'is_ota_channel' => $isOta],
        );

        $reservation = Reservation::create([
            'client_id' => $client->id, 'start_date' => '2026-08-01', 'expire_date' => '2026-08-03',
            'nights' => 2, 'reservation_type' => 0, 'reservation_status' => Reservation::STATUS_CONFIRMED,
            'stay_reason_id' => $stayReason->id, 'reservation_source_id' => $source->id, 'rent_type' => 0,
            'base_price' => 500, 'classification_discount' => 0, 'discount' => 0, 'extras' => 0, 'penalties' => 0,
            'subtotal' => 500, 'taxes' => 75, 'total' => 575,
            'logedin' => $checkedIn ? Reservation::LOGEDIN_IN_HOUSE : Reservation::LOGEDIN_NOT_IN_HOUSE,
            'login_time' => $checkedIn ? '2026-08-01' : null, 'user_id' => $this->apiTestUser()->id,
        ]);
        ReservationRoom::create(['reservation_id' => $reservation->id, 'room_id' => $room->id, 'price' => 575]);

        return [$reservation, $room];
    }

    public function test_marking_a_no_show_cancels_and_frees_the_room(): void
    {
        Carbon::setTestNow('2026-08-05');
        [$reservation] = $this->makePastConfirmedReservation();

        $user = $this->userWithApiPermissions(['cancel reservations']);
        $response = $this->actingAs($user, 'api')->postJson("/api/users/reservations/{$reservation->id}/no-show");

        $response->assertOk();
        $fresh = $reservation->fresh();
        $this->assertTrue((bool) $fresh->is_no_show);
        $this->assertSame(Reservation::STATUS_CANCELLED, (int) $fresh->reservation_status);

        Carbon::setTestNow();
    }

    public function test_a_no_show_sends_status_no_show_to_grms_not_cancelled(): void
    {
        Carbon::setTestNow('2026-08-05');
        [$reservation, $room] = $this->makePastConfirmedReservation();
        $hotelId = (int) $room->building->hotel_id;
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);

        $user = $this->userWithApiPermissions(['cancel reservations']);
        $response = $this->actingAs($user, 'api')->postJson("/api/users/reservations/{$reservation->id}/no-show");
        $response->assertOk();

        $event = GrmsOutboxEvent::where('entity_id', (string) $reservation->id)->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame('reservation.updated', $event->type);
        $this->assertSame('no_show', $event->payload['data']['reservation']['status']);

        Carbon::setTestNow();
    }

    public function test_cannot_mark_no_show_before_the_checkin_date(): void
    {
        Carbon::setTestNow('2026-07-30'); // before start_date 2026-08-01
        [$reservation] = $this->makePastConfirmedReservation();

        $user = $this->userWithApiPermissions(['cancel reservations']);
        $response = $this->actingAs($user, 'api')->postJson("/api/users/reservations/{$reservation->id}/no-show");

        $response->assertStatus(500);
        $response->assertJsonPath('success', false);

        Carbon::setTestNow();
    }

    public function test_cannot_mark_no_show_for_a_checked_in_guest(): void
    {
        Carbon::setTestNow('2026-08-05');
        [$reservation] = $this->makePastConfirmedReservation(checkedIn: true);

        $user = $this->userWithApiPermissions(['cancel reservations']);
        $response = $this->actingAs($user, 'api')->postJson("/api/users/reservations/{$reservation->id}/no-show");

        $response->assertStatus(500);
        $response->assertJsonPath('success', false);

        Carbon::setTestNow();
    }

    public function test_cannot_mark_no_show_for_an_ota_reservation(): void
    {
        Carbon::setTestNow('2026-08-05');
        [$reservation] = $this->makePastConfirmedReservation(isOta: true);

        $user = $this->userWithApiPermissions(['cancel reservations']);
        $response = $this->actingAs($user, 'api')->postJson("/api/users/reservations/{$reservation->id}/no-show");

        $response->assertStatus(500);
        $response->assertJsonPath('success', false);
        $this->assertSame(Reservation::STATUS_CONFIRMED, (int) $reservation->fresh()->reservation_status);

        Carbon::setTestNow();
    }

    public function test_a_no_show_reservation_is_never_refundable(): void
    {
        Carbon::setTestNow('2026-08-05');
        [$reservation] = $this->makePastConfirmedReservation();
        $reservation->payments()->create(['pay' => 575, 'type' => 0, 'user_id' => $this->apiTestUser()->id]);

        $user = $this->userWithApiPermissions(['cancel reservations']);
        $this->actingAs($user, 'api')->postJson("/api/users/reservations/{$reservation->id}/no-show");

        $this->expectException(RefundNotAllowedException::class);
        app(RefundPolicyService::class)->assertRefundable($reservation->fresh());

        Carbon::setTestNow();
    }
}
