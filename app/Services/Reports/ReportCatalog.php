<?php

namespace App\Services\Reports;

class ReportCatalog
{
    /**
     * @return array<int, array{slug: string, category: string, canonical_source: string}>
     */
    public static function all(): array
    {
        return [
            ['slug' => 'overview', 'category' => 'overview', 'canonical_source' => 'RevenueAccrualService+cash+AR'],
            ['slug' => 'room-board', 'category' => 'operational', 'canonical_source' => 'RoomOccupancyService::buildBoard'],
            ['slug' => 'arrivals-departures', 'category' => 'operational', 'canonical_source' => 'Reservation overlap query'],
            ['slug' => 'reservations-list', 'category' => 'operational', 'canonical_source' => 'Reservation period intersection'],
            ['slug' => 'occupancy', 'category' => 'operational', 'canonical_source' => 'ReservationDailyCharge+RoomOccupancyService'],
            ['slug' => 'accrual-revenue', 'category' => 'financial', 'canonical_source' => 'RevenueAccrualService'],
            ['slug' => 'cash-box', 'category' => 'financial', 'canonical_source' => 'ReservationPay by date'],
            ['slug' => 'revenue-summary', 'category' => 'financial', 'canonical_source' => 'RevenueAccrualService'],
            ['slug' => 'accrual-cash-reconciliation', 'category' => 'financial', 'canonical_source' => 'RevenueAccrualService+cash'],
            ['slug' => 'ar-aging', 'category' => 'financial', 'canonical_source' => 'Reservation balance due buckets'],
            ['slug' => 'adjustments', 'category' => 'financial', 'canonical_source' => 'Reservation adjustments'],
            ['slug' => 'tax', 'category' => 'financial', 'canonical_source' => 'RevenueAccrualService tax'],
            ['slug' => 'revpar', 'category' => 'financial', 'canonical_source' => 'RevenueAccrualService+room count'],
            ['slug' => 'by-dimension', 'category' => 'financial', 'canonical_source' => 'RevenueAccrualService details'],
            ['slug' => 'peak-analysis', 'category' => 'financial', 'canonical_source' => 'RevenueAccrualService peak flag'],
            ['slug' => 'payments-refunds', 'category' => 'financial', 'canonical_source' => 'ReservationPay by date'],
            ['slug' => 'closing-package', 'category' => 'financial', 'canonical_source' => 'RevenueAccrualService+cash+AR'],
            ['slug' => 'chart-of-accounts', 'category' => 'accounting', 'canonical_source' => 'FinancialStatementService::accountBalances'],
            ['slug' => 'journal-entries', 'category' => 'accounting', 'canonical_source' => 'JournalEntry model'],
            ['slug' => 'general-ledger', 'category' => 'accounting', 'canonical_source' => 'FinancialStatementService::generalLedger'],
            ['slug' => 'trial-balance', 'category' => 'accounting', 'canonical_source' => 'FinancialStatementService::trialBalance'],
            ['slug' => 'cash-flow', 'category' => 'accounting', 'canonical_source' => 'FinancialStatementService::cashFlow'],
            ['slug' => 'financial-audit-log', 'category' => 'accounting', 'canonical_source' => 'FinancialAuditService'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function allSlugs(): array
    {
        return array_column(self::all(), 'slug');
    }

    public static function slugRequiresAccountingPermission(string $slug): bool
    {
        return in_array($slug, [
            'chart-of-accounts',
            'journal-entries',
            'general-ledger',
            'trial-balance',
            'cash-flow',
            'financial-audit-log',
        ], true);
    }

    public static function find(string $slug): ?array
    {
        foreach (self::all() as $item) {
            if ($item['slug'] === $slug) {
                return $item;
            }
        }

        return null;
    }
}
