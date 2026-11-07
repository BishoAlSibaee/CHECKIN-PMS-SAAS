<?php

namespace Tests\Feature\Grms;

use App\Models\Client;
use App\Models\GrmsOutboxEvent;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\ReservationRoom;
use App\Models\Stay_reason;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GRMS v1 contract: guest.updated, fired from ClientsController::update()
 * for every v1-protocol hotel where the guest has a non-cancelled booking.
 */
class GrmsGuestUpdatedTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    private function makeClientWithActiveReservation(int $hotelId): Client
    {
        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\EssentialReferenceDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $client = Client::create([
            'tenant_id' => Hotel::findOrFail($hotelId)->tenant_id,
            'first_name' => 'Old', 'last_name' => 'Name',
            'international_code' => '+966', 'mobile' => '05'.substr($this->uniqueSuffix(), -8),
            'IdType' => 'PASSPORT', 'IdNumber' => 'TEST-'.$this->uniqueSuffix(),
            'gender' => 'MALE', 'guest_type' => 'VISITOR',
        ]);

        $room = $this->findOrCreateAvailableRoom('2026-09-01', '2026-09-03');
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();

        $reservation = Reservation::create([
            'client_id' => $client->id, 'start_date' => '2026-09-01', 'expire_date' => '2026-09-03',
            'nights' => 2, 'reservation_type' => 0, 'reservation_status' => Reservation::STATUS_CONFIRMED,
            'stay_reason_id' => $stayReason->id, 'reservation_source_id' => $source->id, 'rent_type' => 0,
            'base_price' => 500, 'classification_discount' => 0, 'discount' => 0, 'extras' => 0, 'penalties' => 0,
            'subtotal' => 500, 'taxes' => 75, 'total' => 575, 'logedin' => Reservation::LOGEDIN_NOT_IN_HOUSE,
            'login_time' => '2026-09-01', 'user_id' => $this->apiTestUser()->id,
        ]);
        ReservationRoom::create(['reservation_id' => $reservation->id, 'room_id' => $room->id, 'price' => 575]);

        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);

        return $client;
    }

    public function test_updating_a_clients_contact_details_enqueues_guest_updated(): void
    {
        $hotelId = (int) DB::table('hotels')->orderBy('id')->value('id');
        $client = $this->makeClientWithActiveReservation($hotelId);
        $user = $this->userWithApiPermissions(['manage clients']);

        $response = $this->actingAs($user, 'api')->postJson('/api/users/updateClient', [
            'id' => $client->id,
            'first_name' => 'New',
            'last_name' => 'Name',
            'mobile' => $client->mobile,
            'international_code' => '+966',
        ]);

        $response->assertOk();

        $event = GrmsOutboxEvent::where('entity_type', 'guest')->where('entity_id', (string) $client->id)->first();
        $this->assertNotNull($event);
        $this->assertSame('guest.updated', $event->type);
        $this->assertSame('New', $event->payload['data']['guest']['first_name']);
        $this->assertSame((string) $client->id, $event->payload['data']['guest']['id']);
    }

    public function test_an_unrelated_field_change_does_not_enqueue_anything(): void
    {
        $hotelId = (int) DB::table('hotels')->orderBy('id')->value('id');
        $client = $this->makeClientWithActiveReservation($hotelId);
        $user = $this->userWithApiPermissions(['manage clients']);

        $response = $this->actingAs($user, 'api')->postJson('/api/users/updateClient', [
            'id' => $client->id,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'mobile' => $client->mobile,
            'international_code' => $client->international_code,
            'birth_date' => '1990-01-01',
        ]);

        $response->assertOk();

        $this->assertSame(
            0,
            GrmsOutboxEvent::where('entity_type', 'guest')->where('entity_id', (string) $client->id)->count(),
        );
    }

    public function test_a_legacy_hotel_never_gets_a_guest_updated_event(): void
    {
        $hotelId = (int) DB::table('hotels')->orderBy('id')->value('id');
        $client = $this->makeClientWithActiveReservation($hotelId);
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'legacy']);
        $user = $this->userWithApiPermissions(['manage clients']);

        $response = $this->actingAs($user, 'api')->postJson('/api/users/updateClient', [
            'id' => $client->id,
            'first_name' => 'Changed',
            'last_name' => $client->last_name,
            'mobile' => $client->mobile,
        ]);

        $response->assertOk();

        $this->assertSame(
            0,
            GrmsOutboxEvent::where('entity_type', 'guest')->where('entity_id', (string) $client->id)->count(),
        );
    }
}
