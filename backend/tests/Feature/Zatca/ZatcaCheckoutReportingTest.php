<?php

namespace Tests\Feature\Zatca;

use App\Models\Client;
use App\Models\Reservation;
use App\Models\Reservation_source;
use App\Models\Room;
use App\Models\Stay_reason;
use App\Models\ZatcaInvoice;
use App\Services\ReservationRoomStatusService;
use App\Services\Zatca\ZatcaCheckoutReportingService;
use Carbon\Carbon;
use Database\Seeders\ReservationTestDataSeeder;
use Tests\TestCase;

class ZatcaCheckoutReportingTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql', 'mysql2'];
    }

    public function test_checkout_reports_zatca_and_returns_invoice_summary_when_enabled(): void
    {
        Carbon::setTestNow('2026-08-15');
        config([
            'zatca.enabled' => true,
            'zatca.auto_report_on_checkout' => true,
            'zatca.storage_path' => storage_path('app/zatca-empty-'.uniqid()),
        ]);

        $reservationId = $this->createCheckedInPaidReservation();

        Carbon::setTestNow('2026-08-18');
        $checkOut = $this->actingAs(
            $this->userWithApiPermissions(['view reservations', 'update reservations']),
            'api'
        )->patchJson("/api/users/reservations/{$reservationId}", [
            'logedin' => 0,
        ]);

        $checkOut->assertOk();
        $checkOut->assertJsonPath('success', true);
        $checkOut->assertJsonPath('data.zatca_invoice.status', ZatcaInvoice::STATUS_SKIPPED);
        $this->assertDatabaseHas('zatca_invoices', [
            'reservation_id' => $reservationId,
            'status' => ZatcaInvoice::STATUS_SKIPPED,
        ]);

        Carbon::setTestNow();
    }

    public function test_checkout_skips_zatca_when_disabled(): void
    {
        Carbon::setTestNow('2026-08-15');
        config([
            'zatca.enabled' => false,
            'zatca.auto_report_on_checkout' => true,
        ]);

        $reservationId = $this->createCheckedInPaidReservation();

        Carbon::setTestNow('2026-08-18');
        $checkOut = $this->actingAs(
            $this->userWithApiPermissions(['view reservations', 'update reservations']),
            'api'
        )->patchJson("/api/users/reservations/{$reservationId}", [
            'logedin' => 0,
        ]);

        $checkOut->assertOk();
        $checkOut->assertJsonPath('data.zatca_invoice', null);
        $this->assertDatabaseMissing('zatca_invoices', [
            'reservation_id' => $reservationId,
        ]);

        Carbon::setTestNow();
    }

    public function test_checkout_skips_zatca_for_a_non_sar_tenant_even_when_enabled(): void
    {
        Carbon::setTestNow('2026-08-15');
        config([
            'zatca.enabled' => true,
            'zatca.auto_report_on_checkout' => true,
            'zatca.storage_path' => storage_path('app/zatca-empty-'.uniqid()),
        ]);

        $reservationId = $this->createCheckedInPaidReservation();

        $roomId = \App\Models\ReservationRoom::where('reservation_id', $reservationId)->value('room_id');
        $hotelId = \DB::table('buildings')
            ->join('rooms', 'rooms.building_id', '=', 'buildings.id')
            ->where('rooms.id', $roomId)
            ->value('buildings.hotel_id');
        $tenantId = \App\Models\Hotel::find($hotelId)->tenant_id;
        \App\Models\Tenant::where('id', $tenantId)->update(['currency' => 'USD']);

        Carbon::setTestNow('2026-08-18');
        $checkOut = $this->actingAs(
            $this->userWithApiPermissions(['view reservations', 'update reservations']),
            'api'
        )->patchJson("/api/users/reservations/{$reservationId}", [
            'logedin' => 0,
        ]);

        $checkOut->assertOk();
        $checkOut->assertJsonPath('data.zatca_invoice', null);
        $this->assertDatabaseMissing('zatca_invoices', [
            'reservation_id' => $reservationId,
        ]);

        Carbon::setTestNow();
    }

    public function test_reservation_show_includes_zatca_qr_code(): void
    {
        $reservation = $this->createBareReservation([
            'logedin' => Reservation::LOGEDIN_NOT_IN_HOUSE,
        ]);

        ZatcaInvoice::query()->create([
            'reservation_id' => $reservation->id,
            'invoice_number' => 'HOT-R'.$reservation->id.'-QR',
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'status' => ZatcaInvoice::STATUS_REPORTED,
            'reporting_status' => 'REPORTED',
            'http_status' => 200,
            'qr_code' => 'BASE64TLVQRCODEEXAMPLE',
            'reported_at' => now(),
        ]);

        $show = $this->actingAs(
            $this->userWithApiPermissions(['view reservations']),
            'api'
        )->getJson("/api/users/reservations/{$reservation->id}");

        $show->assertOk();
        $show->assertJsonPath('data.zatca_invoice.qr_code', 'BASE64TLVQRCODEEXAMPLE');
        $show->assertJsonPath('data.zatca_invoice.status', ZatcaInvoice::STATUS_REPORTED);
    }

    public function test_report_skips_when_credentials_missing(): void
    {
        config([
            'zatca.enabled' => true,
            'zatca.auto_report_on_checkout' => true,
            'zatca.storage_path' => storage_path('app/zatca-test-empty-'.uniqid()),
        ]);

        $reservation = $this->createBareReservation();

        $invoice = app(ZatcaCheckoutReportingService::class)->reportReservationCheckout((int) $reservation->id);

        $this->assertSame(ZatcaInvoice::STATUS_SKIPPED, $invoice->status);
        $this->assertStringContainsString('credentials', strtolower((string) $invoice->error_message));
        $this->assertDatabaseHas('zatca_invoices', [
            'reservation_id' => $reservation->id,
            'status' => ZatcaInvoice::STATUS_SKIPPED,
        ]);
    }

    public function test_report_skips_zero_total_reservation(): void
    {
        config([
            'zatca.enabled' => true,
            'zatca.auto_report_on_checkout' => true,
        ]);

        $reservation = $this->createBareReservation([
            'subtotal' => 0,
            'taxes' => 0,
            'total' => 0,
            'nights' => 1,
            'expire_date' => '2026-08-16',
        ]);

        $invoice = app(ZatcaCheckoutReportingService::class)->reportReservationCheckout((int) $reservation->id);

        $this->assertSame(ZatcaInvoice::STATUS_SKIPPED, $invoice->status);
        $this->assertStringContainsString('zero', strtolower((string) $invoice->error_message));
    }

    public function test_already_reported_invoice_is_idempotent(): void
    {
        config([
            'zatca.enabled' => true,
            'zatca.auto_report_on_checkout' => true,
        ]);

        $reservation = $this->createBareReservation([
            'nights' => 1,
            'expire_date' => '2026-08-16',
        ]);

        // reportReservationCheckout() keys its idempotency check off
        // invoice_id (a FK to the dedicated invoices table), not
        // reservation_id directly — so the "already reported" state has to
        // come from a real first call, not a hand-built ZatcaInvoice row
        // with a guessed invoice_id (which the service would never match,
        // defeating the idempotency check entirely). No ZATCA credentials
        // are configured in tests, so this first call lands as SKIPPED (see
        // test_report_skips_when_credentials_missing) — flip it to REPORTED
        // to simulate a previously successful report.
        $first = app(ZatcaCheckoutReportingService::class)->reportReservationCheckout((int) $reservation->id);
        $first->update([
            'status' => ZatcaInvoice::STATUS_REPORTED,
            'reporting_status' => 'REPORTED',
            'http_status' => 200,
            'reported_at' => now(),
        ]);

        $invoice = app(ZatcaCheckoutReportingService::class)->reportReservationCheckout((int) $reservation->id);

        $this->assertSame($first->id, $invoice->id);
        $this->assertSame(ZatcaInvoice::STATUS_REPORTED, $invoice->status);
        $this->assertSame(1, ZatcaInvoice::query()->where('reservation_id', $reservation->id)->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createBareReservation(array $overrides = []): Reservation
    {
        $this->artisan('db:seed', [
            '--class' => ReservationTestDataSeeder::class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        $client = Client::first();
        $stayReason = Stay_reason::first();
        $source = Reservation_source::first();
        $this->assertNotNull($client);
        $this->assertNotNull($stayReason);
        $this->assertNotNull($source);

        return Reservation::query()->create(array_merge([
            'client_id' => $client->id,
            'start_date' => '2026-08-15',
            'expire_date' => '2026-08-18',
            'nights' => 3,
            'reservation_type' => 0,
            'reservation_status' => Reservation::STATUS_CONFIRMED,
            'stay_reason_id' => $stayReason->id,
            'reservation_source_id' => $source->id,
            'rent_type' => 0,
            'base_price' => 100,
            'discount' => 0,
            'extras' => 0,
            'penalties' => 0,
            'subtotal' => 100,
            'taxes' => 15,
            'total' => 115,
            'logedin' => Reservation::LOGEDIN_NOT_IN_HOUSE,
            'user_id' => $this->apiTestUser()->id,
        ], $overrides));
    }

    private function createCheckedInPaidReservation(): int
    {
        $reservation = $this->createBareReservation([
            'logedin' => Reservation::LOGEDIN_IN_HOUSE,
            'login_time' => '2026-08-15',
            'subtotal' => 100,
            'taxes' => 15,
            'total' => 115,
        ]);

        $room = $this->findOrCreateAvailableRoom('2026-08-15', '2026-08-18');
        $this->assertNotNull($room);

        \App\Models\ReservationRoom::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $room->id,
        ]);

        \App\Models\ReservationPay::query()->create([
            'reservation_id' => $reservation->id,
            'pay' => 115,
            'type' => \App\Models\ReservationPay::TYPE_PAYMENT,
            'user_id' => $this->apiTestUser()->id,
        ]);

        Room::where('id', $room->id)->update([
            'roomStatus' => ReservationRoomStatusService::ROOM_OCCUPIED,
        ]);

        return (int) $reservation->id;
    }
}
