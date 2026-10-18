<?php

namespace Tests\Unit\Support;

use App\Support\OrphanedInnoDbRepair;
use App\Support\SchemaTableHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrphanedInnoDbRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_is_idempotent_on_healthy_schema(): void
    {
        $this->assertTrue(SchemaTableHealth::isUsable('reservations'));

        $repaired = (new OrphanedInnoDbRepair)->repair();

        $this->assertSame([], $repaired);
        $this->assertTrue(Schema::hasColumn('reservations', 'price_calculation_mode'));
        $this->assertTrue(SchemaTableHealth::isUsable('reservation_pay'));
        $this->assertTrue(SchemaTableHealth::isUsable('room_prices'));
        $this->assertTrue(SchemaTableHealth::isUsable('refund_policies'));
        $this->assertTrue(SchemaTableHealth::isUsable('report_exports'));
    }

    public function test_ensure_reservations_recreates_missing_table(): void
    {
        $repair = new OrphanedInnoDbRepair;
        $repair->forceDrop('reservation_pay');
        $repair->forceDrop('reservations');

        $this->assertFalse(Schema::hasTable('reservations'));

        $repair->ensureReservationsTable();
        $repair->ensureReservationPayTable();
        $repair->ensurePriceCalculationModeColumn();

        $this->assertTrue(SchemaTableHealth::isUsable('reservations'));
        $this->assertTrue(Schema::hasColumn('reservations', 'classification_discount'));
        $this->assertTrue(Schema::hasColumn('reservations', 'price_calculation_mode'));
        $this->assertTrue(SchemaTableHealth::isUsable('reservation_pay'));

        // ensureReservationsTable() is a bare-bones EMERGENCY schema — just
        // enough columns for this repair utility's own correctness checks
        // above — not a full clone of every migration since. Left as-is,
        // the real `reservations` table stays replaced by this stripped
        // schema (missing confirmation_code, external_booking_id, etc.) for
        // the rest of this PHPUnit process, since the migrations tracking
        // table still thinks those later migrations already ran and won't
        // re-apply them on a plain `migrate`. migrate:fresh forces a full,
        // correct rebuild back to the real current schema.
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
    }
}
