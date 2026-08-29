<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Schema::hasTable() can return true for MySQL 1932 orphans
 * (dictionary entry exists, InnoDB tablespace missing).
 */
class SchemaTableHealth
{
    public static function isUsable(string $table): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        try {
            DB::table($table)->limit(1)->get();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function isOrphanedInEngine(string $table): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        try {
            $status = DB::select('SHOW TABLE STATUS LIKE ?', [$table]);
            if ($status === []) {
                return false;
            }

            $row = $status[0];
            $engine = $row->Engine ?? null;
            $comment = (string) ($row->Comment ?? '');

            if ($engine === null || $engine === '') {
                return true;
            }

            return str_contains($comment, "doesn't exist in engine");
        } catch (Throwable) {
            return ! self::isUsable($table);
        }
    }
}
