<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuilds MySQL InnoDB tables that appear in the dictionary but have no engine files (error 1932).
 * Safe no-op when tables are healthy — used by migrations so clones / pull+migrate self-heal.
 */
class OrphanedInnoDbRepair
{
    /** @var list<string> */
    public const REPAIRABLE_TABLES = [
        'reservation_pay',
        'reservations',
        'room_prices',
        'refund_policies',
        'report_exports',
    ];

    /**
     * @return list<string> tables that were rebuilt
     */
    public function repair(): array
    {
        $repaired = [];

        foreach (self::REPAIRABLE_TABLES as $table) {
            if (SchemaTableHealth::isUsable($table)) {
                continue;
            }

            if (Schema::hasTable($table) || SchemaTableHealth::isOrphanedInEngine($table)) {
                $this->forceDrop($table);
                $repaired[] = $table;
            } elseif (! Schema::hasTable($table)) {
                // Missing dictionary entry but stray .ibd may remain (error 1813).
                $this->removeOrphanTablespaceFile($table);
                $repaired[] = $table;
            }
        }

        $this->ensureReservationsTable();
        $this->ensureReservationPayTable();
        $this->ensureRoomPricesTable();
        $this->ensureRefundPoliciesTable();
        $this->ensureReportExportsTable();
        $this->ensurePriceCalculationModeColumn();
        $this->purgeOrphanReservationChildren();

        return array_values(array_unique($repaired));
    }

    public function forceDrop(string $table): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->removeOrphanTablespaceFile($table);
    }

    /**
     * After a 1932 DROP, MySQL may leave a stray .ibd that blocks CREATE (error 1813).
     */
    public function removeOrphanTablespaceFile(string $table): void
    {
        $datadir = DB::selectOne("SHOW VARIABLES LIKE 'datadir'");
        if (! $datadir || empty($datadir->Value)) {
            return;
        }

        $database = DB::connection()->getDatabaseName();
        $path = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $datadir->Value), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$database
            .DIRECTORY_SEPARATOR.$table.'.ibd';

        if (! is_file($path)) {
            return;
        }

        @chmod($path, 0666);
        if (! @unlink($path)) {
            throw new \RuntimeException(
                "Could not remove orphan tablespace file [{$path}]. Stop MySQL, delete the file, start MySQL, then re-run db:repair-orphaned-tables."
            );
        }
    }

    private function createTableOrRepairTablespace(string $table, callable $blueprint): void
    {
        $attempts = 0;
        $last = null;

        while ($attempts < 3) {
            $attempts++;
            try {
                if (Schema::hasTable($table) && ! SchemaTableHealth::isUsable($table)) {
                    $this->forceDrop($table);
                } elseif (! Schema::hasTable($table)) {
                    $this->removeOrphanTablespaceFile($table);
                }

                Schema::create($table, $blueprint);

                return;
            } catch (\Throwable $e) {
                $last = $e;
                $msg = $e->getMessage();
                $retryable = str_contains($msg, '1813')
                    || str_contains($msg, 'Tablespace')
                    || str_contains($msg, '1050')
                    || str_contains($msg, 'already exists')
                    || str_contains($msg, 'errno: 168')
                    || str_contains($msg, '1005');

                if (! $retryable || $attempts >= 3) {
                    throw $e;
                }

                try {
                    $this->forceDrop($table);
                } catch (\Throwable) {
                    // Dictionary may already be empty; still clear stray .ibd.
                }
                $this->removeOrphanTablespaceFile($table);
            }
        }

        throw $last ?? new \RuntimeException("Failed to create table [{$table}]");
    }

    public function ensureReservationsTable(): void
    {
        if (SchemaTableHealth::isUsable('reservations')) {
            return;
        }

        if (Schema::hasTable('reservations')) {
            $this->forceDrop('reservations');
        } else {
            $this->removeOrphanTablespaceFile('reservations');
        }

        $this->createTableOrRepairTablespace('reservations', function (Blueprint $table) {
            $table->id();
            $table->integer('client_id')->comment('ID of the client making the reservation');
            $table->date('start_date')->comment('the reservation start date');
            $table->integer('nights')->default(1)->comment('the reservation nights');
            $table->date('expire_date')->comment('the reservation expire date');
            $table->tinyInteger('reservation_type')->default(0)->comment('0=> Single 1=> collective');
            $table->tinyInteger('reservation_status')->default(0)->comment('0=> Unconfirmed 1=> Confirmed');
            $table->integer('stay_reason_id');
            $table->integer('reservation_source_id');
            $table->tinyInteger('rent_type')->default(0)->comment('0=>daily 1=>monthly 2=>annual');
            $table->tinyInteger('price_calculation_mode')->default(0);
            $table->double('base_price')->comment('the default room price');
            $table->double('discount')->default(0)->comment('the discount granted to the reservation');
            $table->decimal('classification_discount', 12, 2)->default(0);
            $table->unsignedBigInteger('guest_classification_id')->nullable();
            $table->unsignedBigInteger('classification_discount_id')->nullable();
            $table->double('extras')->default(0)->comment('the total of any additional values');
            $table->double('penalties')->default(0)->comment('the total of the penalties if any');
            $table->double('subtotal')->comment('the subtotal of reservation');
            $table->double('taxes')->comment('the total of taxes');
            $table->double('total')->comment('the final total of reservetion');
            $table->tinyInteger('logedin')->comment('0=> Guest is Not Logged In 1=> Guest is Logged In');
            $table->date('login_time')->nullable()->comment('the time the guest actually loged in at');
            $table->integer('user_id')->comment('the user who make the reservation');
            $table->timestamps();

            $table->index('reservation_status', 'reservations_status_idx');
            $table->index('start_date', 'reservations_start_date_idx');
            $table->index('expire_date', 'reservations_expire_date_idx');
            $table->index('logedin', 'reservations_logedin_idx');
            $table->index(
                ['reservation_status', 'start_date', 'expire_date'],
                'reservations_status_dates_idx'
            );
        });
    }

    public function ensureReservationPayTable(): void
    {
        if (SchemaTableHealth::isUsable('reservation_pay')) {
            return;
        }

        if (Schema::hasTable('reservation_pay')) {
            $this->forceDrop('reservation_pay');
        } else {
            $this->removeOrphanTablespaceFile('reservation_pay');
        }

        $this->createTableOrRepairTablespace('reservation_pay', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id');
            $table->float('pay');
            $table->tinyInteger('type')->default(0)->comment('0: payment to hotel, 1: refund from hotel');
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();

            $table->foreign('reservation_id')->references('id')->on('reservations')->onDelete('cascade');
            $table->index('reservation_id');
            $table->index('user_id');
        });
    }

    public function ensureRoomPricesTable(): void
    {
        if (SchemaTableHealth::isUsable('room_prices')) {
            return;
        }

        if (Schema::hasTable('room_prices')) {
            $this->forceDrop('room_prices');
        } else {
            $this->removeOrphanTablespaceFile('room_prices');
        }

        $this->createTableOrRepairTablespace('room_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_room_id')->nullable()->constrained('reservation_rooms')->onDelete('cascade');
            $table->double('pricing_plan_daily')->nullable();
            $table->double('pricing_plan_monthly')->nullable();
            $table->double('max_price')->nullable();
            $table->double('min_price')->nullable();
            $table->double('max_month')->nullable();
            $table->double('min_month')->nullable();
            $table->date('start_plan')->nullable();
            $table->date('end_plan')->nullable();
            $table->date('date')->nullable();
            $table->timestamps();
        });
    }

    public function ensureRefundPoliciesTable(): void
    {
        if (SchemaTableHealth::isUsable('refund_policies')) {
            return;
        }

        if (Schema::hasTable('refund_policies')) {
            $this->forceDrop('refund_policies');
        } else {
            $this->removeOrphanTablespaceFile('refund_policies');
        }

        $this->createTableOrRepairTablespace('refund_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedTinyInteger('rent_type')->nullable()
                ->comment('null=all, 0=daily, 1=monthly, 2=annual');
            $table->string('timing', 20)->default('before_start');
            $table->string('threshold_mode', 20)->default('fixed_days');
            $table->decimal('threshold_percent', 5, 2)->nullable();
            $table->integer('days_threshold')->nullable();
            $table->integer('days_before_checkin');
            $table->decimal('refund_percent', 5, 2);
            $table->string('refund_basis', 20)->default('total');
            $table->tinyInteger('payment_status')->nullable()->default(null)
                ->comment('0:no pay,1:partial,2:full');
            $table->json('payment_statuses')->nullable()
                ->comment('none, partial, full, paid — empty/null matches any');
            $table->tinyInteger('during_stay')->default(0)->comment('0:pre-checkin,1:during stay');
            $table->unsignedTinyInteger('priority')->default(0);
            $table->timestamps();
        });
    }

    public function ensureReportExportsTable(): void
    {
        if (SchemaTableHealth::isUsable('report_exports')) {
            return;
        }

        if (Schema::hasTable('report_exports')) {
            $this->forceDrop('report_exports');
        } else {
            $this->removeOrphanTablespaceFile('report_exports');
        }

        $this->createTableOrRepairTablespace('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 64);
            $table->string('file_format', 10)->default('excel');
            $table->string('recipient_email');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->date('compare_start_date')->nullable();
            $table->date('compare_end_date')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('file_path')->nullable();
            $table->string('download_token', 64)->unique();
            $table->unsignedInteger('row_count')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('user_id');
        });
    }

    public function ensurePriceCalculationModeColumn(): void
    {
        if (! SchemaTableHealth::isUsable('reservations')) {
            return;
        }

        if (! Schema::hasColumn('reservations', 'price_calculation_mode')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->tinyInteger('price_calculation_mode')->default(0)->after('rent_type');
            });
        }
    }

    /**
     * Child rows that survived after a 1932 orphan of `reservations` would attach to new IDs.
     */
    public function purgeOrphanReservationChildren(): void
    {
        if (! SchemaTableHealth::isUsable('reservations')) {
            return;
        }

        if (SchemaTableHealth::isUsable('reservation_rooms')) {
            DB::table('reservation_rooms')
                ->whereNotIn('reservation_id', DB::table('reservations')->select('id'))
                ->delete();
        }

        if (SchemaTableHealth::isUsable('reservation_daily_charges')
            && SchemaTableHealth::isUsable('reservation_rooms')) {
            DB::table('reservation_daily_charges')
                ->whereNotIn('reservation_room_id', DB::table('reservation_rooms')->select('id'))
                ->delete();
        }

        if (SchemaTableHealth::isUsable('reservation_change_logs')) {
            DB::table('reservation_change_logs')
                ->whereNotIn('reservation_id', DB::table('reservations')->select('id'))
                ->delete();
        }

        foreach (['reservation_extend', 'reservation_taxes', 'reservation_penalties'] as $child) {
            if (! SchemaTableHealth::isUsable($child)) {
                continue;
            }

            DB::table($child)
                ->whereNotIn('reservation_id', DB::table('reservations')->select('id'))
                ->delete();
        }
    }
}
