<?php

namespace Tests\Feature\Grms;

use App\Models\Client;
use App\Models\GrmsHotelCredential;
use App\Models\PosCharge;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay_reason;
use App\Services\Grms\GrmsCredentialService;
use App\Services\Grms\GrmsSignatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class GrmsV1ChargeTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    private function hotelId(): int
    {
        return (int) DB::table('hotels')->orderBy('id')->value('id');
    }

    private function inboundCredential(int $hotelId): array
    {
        $result = app(GrmsCredentialService::class)->generate($hotelId, GrmsHotelCredential::DIRECTION_INBOUND);

        return [$result['credential']->key_id, $result['secret']];
    }

    private function postSigned(string $uri, array $body, string $keyId, string $secret, string $method = 'POST')
    {
        $rawBody = json_encode($body);
        $timestamp = (string) time();
        $signature = app(GrmsSignatureService::class)->sign($timestamp, $rawBody, $secret);

        return $this->call(
            $method,
            $uri,
            [], [], [],
            $this->transformHeadersToServerVars([
                'X-Key-Id' => $keyId,
                'X-Timestamp' => $timestamp,
                'X-Signature' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ]),
            $rawBody,
        );
    }

    private function checkedInReservationWithRoom(int $hotelId): array
    {
        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\EssentialReferenceDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $client = Client::first() ?? Client::create([
            'first_name' => 'Guest',
            'last_name' => 'Charge'.$this->uniqueSuffix(),
            'international_code' => '+966',
            'mobile' => '05'.substr($this->uniqueSuffix(), -8),
            'IdType' => 'PASSPORT',
            'IdNumber' => 'TEST-'.$this->uniqueSuffix(),
            'gender' => 'MALE',
            'guest_type' => 'VISITOR',
        ]);
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        $room = Room::whereHas('building', fn ($q) => $q->where('hotel_id', $hotelId))->first();

        $reservation = Reservation::query()->create([
            'client_id' => $client->id,
            'start_date' => now()->toDateString(),
            'expire_date' => now()->addDays(2)->toDateString(),
            'nights' => 2,
            'reservation_type' => 0,
            'reservation_status' => Reservation::STATUS_CONFIRMED,
            'stay_reason_id' => $stayReason->id,
            'reservation_source_id' => $source->id,
            'rent_type' => 0,
            'base_price' => 500,
            'classification_discount' => 0,
            'discount' => 0,
            'extras' => 0,
            'penalties' => 0,
            'subtotal' => 500,
            'taxes' => 75,
            'total' => 575,
            'logedin' => Reservation::LOGEDIN_IN_HOUSE,
            'login_time' => now()->toDateString(),
            'user_id' => $this->apiTestUser()->id,
        ]);

        ReservationRoom::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $room->id,
            'price' => 575,
            'logedin' => ReservationRoom::LOGEDIN_IN_HOUSE,
            'login_time' => now(),
        ]);

        return [$reservation, $room];
    }

    /** @return array{0: Reservation, 1: Room, 2: Room} room B already checked out, room A still in-house */
    private function reservationWithOneRoomCheckedOut(int $hotelId): array
    {
        [$reservation, $roomA] = $this->checkedInReservationWithRoom($hotelId);

        $roomB = Room::whereHas('building', fn ($q) => $q->where('hotel_id', $hotelId))
            ->where('id', '!=', $roomA->id)
            ->first() ?? $this->createIsolatedTestRoom();

        ReservationRoom::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $roomB->id,
            'price' => 0,
            'logedin' => ReservationRoom::LOGEDIN_NOT_IN_HOUSE,
            'logout_time' => now(),
        ]);

        return [$reservation, $roomA, $roomB];
    }

    public function test_tax_inclusive_charge_is_posted_and_idempotent(): void
    {
        $hotelId = $this->hotelId();
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);
        [$keyId, $secret] = $this->inboundCredential($hotelId);
        [$reservation, $room] = $this->checkedInReservationWithRoom($hotelId);

        $chargeId = (string) Str::uuid();
        $payload = [
            'charge_id' => $chargeId,
            'reservation_id' => (string) $reservation->id,
            'room' => ['code' => $room->number],
            'department' => 'restaurant',
            'items' => [[
                'code' => 'FNB-001',
                'description' => 'Omelette',
                'quantity' => 2,
                'unit_price' => ['amount' => 1200, 'currency' => 'SAR'],
            ]],
            'prices_include_tax' => true,
        ];

        $first = $this->postSigned('/api/grms/v1/charges', $payload, $keyId, $secret);
        $first->assertStatus(200);
        $first->assertJsonPath('status', 'posted');
        $firstFolioId = $first->json('folio_entry_id');

        $this->assertDatabaseHas('pos_charges', [
            'grms_charge_id' => $chargeId,
            'reservation_id' => $reservation->id,
        ]);

        $second = $this->postSigned('/api/grms/v1/charges', $payload, $keyId, $secret);
        $second->assertStatus(200);
        $this->assertSame($firstFolioId, $second->json('folio_entry_id'));
        $this->assertSame(1, PosCharge::where('grms_charge_id', $chargeId)->count());
    }

    /** @dataProvider otherAccountDepartments */
    public function test_other_and_room_service_departments_post_under_the_other_category(string $department): void
    {
        $hotelId = $this->hotelId();
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);
        [$keyId, $secret] = $this->inboundCredential($hotelId);
        [$reservation, $room] = $this->checkedInReservationWithRoom($hotelId);

        $response = $this->postSigned('/api/grms/v1/charges', [
            'charge_id' => (string) Str::uuid(),
            'reservation_id' => (string) $reservation->id,
            'room' => ['code' => $room->number],
            'department' => $department,
            'items' => [['description' => 'Parking', 'quantity' => 1, 'unit_price' => ['amount' => 5000]]],
        ], $keyId, $secret);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'posted');
        $this->assertDatabaseHas('pos_charges', [
            'reservation_id' => $reservation->id,
            'pos_category_id' => \App\Models\PosCategory::where('name_en', 'Other')->whereNull('hotel_id')->value('id'),
        ]);
    }

    public static function otherAccountDepartments(): array
    {
        return [['other'], ['room_service']];
    }

    public function test_charge_for_an_unknown_reservation_is_rejected(): void
    {
        $hotelId = $this->hotelId();
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);
        [$keyId, $secret] = $this->inboundCredential($hotelId);

        $response = $this->postSigned('/api/grms/v1/charges', [
            'charge_id' => (string) Str::uuid(),
            'reservation_id' => '999999999',
            'room' => ['code' => '101'],
            'department' => 'restaurant',
            'items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => ['amount' => 100]]],
        ], $keyId, $secret);

        $response->assertStatus(422);
        $response->assertJsonPath('reason', 'reservation_not_found');
    }

    /**
     * Per-room check-in/check-out (ReservationRoomStatusService::
     * recalculateAggregateLogedin): a charge must be judged on the SPECIFIC
     * room it targets, not the reservation's aggregate — one room checked
     * out doesn't close the folio for a room that's still occupied.
     */
    public function test_a_charge_against_a_checked_out_room_is_rejected_even_though_another_room_is_still_in_house(): void
    {
        $hotelId = $this->hotelId();
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);
        [$keyId, $secret] = $this->inboundCredential($hotelId);
        [$reservation, $roomA, $roomB] = $this->reservationWithOneRoomCheckedOut($hotelId);

        $response = $this->postSigned('/api/grms/v1/charges', [
            'charge_id' => (string) Str::uuid(),
            'reservation_id' => (string) $reservation->id,
            'room' => ['code' => $roomB->number],
            'department' => 'restaurant',
            'items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => ['amount' => 100]]],
        ], $keyId, $secret);

        $response->assertStatus(422);
        $response->assertJsonPath('reason', 'guest_checked_out');
    }

    public function test_a_charge_against_the_still_in_house_room_posts_despite_the_other_rooms_checkout(): void
    {
        $hotelId = $this->hotelId();
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);
        [$keyId, $secret] = $this->inboundCredential($hotelId);
        [$reservation, $roomA] = $this->reservationWithOneRoomCheckedOut($hotelId);

        $response = $this->postSigned('/api/grms/v1/charges', [
            'charge_id' => (string) Str::uuid(),
            'reservation_id' => (string) $reservation->id,
            'room' => ['code' => $roomA->number],
            'department' => 'restaurant',
            'items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => ['amount' => 100]]],
        ], $keyId, $secret);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'posted');
    }

    /**
     * The exact bug found via the real cross-system link test: a charge
     * whose item carries no `code` at all (opaque and optional per contract
     * section 6) must still post using `category`/`department` alone.
     */
    public function test_a_charge_with_no_item_code_still_posts(): void
    {
        $hotelId = $this->hotelId();
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);
        [$keyId, $secret] = $this->inboundCredential($hotelId);
        [$reservation, $room] = $this->checkedInReservationWithRoom($hotelId);

        $response = $this->postSigned('/api/grms/v1/charges', [
            'charge_id' => (string) Str::uuid(),
            'reservation_id' => (string) $reservation->id,
            'room' => ['code' => $room->number],
            'department' => 'restaurant',
            'items' => [[
                'description' => 'Omelette',
                'quantity' => 3,
                'unit_price' => ['amount' => 1200, 'currency' => 'SAR'],
            ]],
            'prices_include_tax' => true,
        ], $keyId, $secret);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'posted');
    }

    public function test_voiding_a_charge_reverses_it(): void
    {
        $hotelId = $this->hotelId();
        DB::table('hotels')->where('id', $hotelId)->update(['pms_protocol' => 'v1']);
        [$keyId, $secret] = $this->inboundCredential($hotelId);
        [$reservation, $room] = $this->checkedInReservationWithRoom($hotelId);

        $chargeId = (string) Str::uuid();
        $this->postSigned('/api/grms/v1/charges', [
            'charge_id' => $chargeId,
            'reservation_id' => (string) $reservation->id,
            'room' => ['code' => $room->number],
            'department' => 'restaurant',
            'items' => [['description' => 'x', 'quantity' => 1, 'unit_price' => ['amount' => 1000]]],
        ], $keyId, $secret);

        $voidResponse = $this->postSigned("/api/grms/v1/charges/{$chargeId}", [], $keyId, $secret, 'DELETE');
        $voidResponse->assertStatus(200);

        $this->assertDatabaseHas('pos_charges', [
            'grms_charge_id' => $chargeId,
            'status' => PosCharge::STATUS_VOIDED,
        ]);
    }
}
