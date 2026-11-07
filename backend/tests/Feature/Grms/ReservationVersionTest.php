<?php

namespace Tests\Feature\Grms;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\Stay_reason;
use Tests\TestCase;

/**
 * GRMS v1 contract's staleness check relies on this: an event whose version
 * is older than what we already applied is ignored. See
 * Reservation::booted()'s saving() hook.
 */
class ReservationVersionTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    private function makeReservation(): Reservation
    {
        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\EssentialReferenceDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $client = Client::first() ?? Client::create([
            'first_name' => 'Guest',
            'last_name' => 'Version'.$this->uniqueSuffix(),
            'international_code' => '+966',
            'mobile' => '05'.substr($this->uniqueSuffix(), -8),
            'IdType' => 'PASSPORT',
            'IdNumber' => 'TEST-'.$this->uniqueSuffix(),
            'gender' => 'MALE',
            'guest_type' => 'VISITOR',
        ]);
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();

        return Reservation::query()->create([
            'client_id' => $client->id,
            'start_date' => '2026-08-10',
            'expire_date' => '2026-08-12',
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
            'logedin' => Reservation::LOGEDIN_NOT_IN_HOUSE,
            'login_time' => '2026-08-10',
            'user_id' => $this->apiTestUser()->id,
        ]);
    }

    public function test_a_new_reservation_starts_at_version_one(): void
    {
        $reservation = $this->makeReservation();

        $this->assertSame(1, $reservation->fresh()->version);
    }

    public function test_version_increments_on_a_real_update(): void
    {
        $reservation = $this->makeReservation();

        $reservation->discount = 50;
        $reservation->save();

        $this->assertSame(2, $reservation->fresh()->version);

        $reservation->extras = 10;
        $reservation->save();

        $this->assertSame(3, $reservation->fresh()->version);
    }

    public function test_version_does_not_increment_on_a_no_op_save(): void
    {
        $reservation = $this->makeReservation();

        $reservation->save();
        $reservation->save();

        $this->assertSame(1, $reservation->fresh()->version);
    }
}
