<?php

namespace App\Services\Reports;

use App\Models\ChartOfAccount;

class AccountingReportFilters
{
    public function __construct(
        public readonly ?string $accountType = null,
        public readonly ?int $accountId = null,
        public readonly ?string $search = null,
        public readonly bool $hideZero = false,
        public readonly ?int $reservationId = null,
        public readonly ?string $sourceType = null,
        public readonly ?string $operation = null,
        public readonly ?float $minAmount = null,
        public readonly ?float $maxAmount = null,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public static function from(array $params): self
    {
        $accountType = ! empty($params['account_type']) ? (string) $params['account_type'] : null;
        if ($accountType !== null && ! in_array($accountType, ChartOfAccount::TYPES, true)) {
            $accountType = null;
        }

        $sourceType = ! empty($params['source_type']) ? (string) $params['source_type'] : null;
        if ($sourceType !== null && ! in_array($sourceType, ['reservation_pay', 'daily_charge', 'manual'], true)) {
            $sourceType = null;
        }

        $operation = ! empty($params['operation']) ? (string) $params['operation'] : null;
        if ($operation !== null && ! in_array($operation, [
            'payment_posted',
            'refund_posted',
            'accrual_posted',
            'journal_entry_created',
            'period_closed',
        ], true)) {
            $operation = null;
        }

        $search = trim((string) ($params['search'] ?? $params['q'] ?? ''));
        if ($search === '') {
            $search = null;
        }

        return new self(
            accountType: $accountType,
            accountId: ! empty($params['account_id']) ? (int) $params['account_id'] : null,
            search: $search,
            hideZero: filter_var($params['hide_zero'] ?? false, FILTER_VALIDATE_BOOLEAN),
            reservationId: ! empty($params['reservation_id']) ? (int) $params['reservation_id'] : null,
            sourceType: $sourceType,
            operation: $operation,
            minAmount: self::parseAmount($params['min_amount'] ?? null),
            maxAmount: self::parseAmount($params['max_amount'] ?? null),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{
     *     search_keys?: array<int, string>,
     *     zero_keys?: array<int, string>,
     *     type_key?: string,
     *     reservation_key?: string,
     *     operation_key?: string,
     *     amount_keys?: array<int, string>
     * }  $config
     * @return array<int, array<string, mixed>>
     */
    public function applyToRows(array $rows, array $config): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $row) => $this->matchesRow($row, $config)
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{
     *     search_keys?: array<int, string>,
     *     zero_keys?: array<int, string>,
     *     type_key?: string,
     *     reservation_key?: string,
     *     operation_key?: string,
     *     amount_keys?: array<int, string>
     * }  $config
     */
    public function matchesRow(array $row, array $config): bool
    {
        $typeKey = $config['type_key'] ?? null;
        if ($typeKey && $this->accountType && (string) ($row[$typeKey] ?? '') !== $this->accountType) {
            return false;
        }

        $reservationKey = $config['reservation_key'] ?? null;
        if ($reservationKey && $this->reservationId !== null) {
            $rowReservationId = (int) ($row[$reservationKey] ?? 0);
            if ($rowReservationId !== $this->reservationId) {
                return false;
            }
        }

        $operationKey = $config['operation_key'] ?? null;
        if ($operationKey && $this->operation && (string) ($row[$operationKey] ?? '') !== $this->operation) {
            return false;
        }

        $searchKeys = $config['search_keys'] ?? [];
        if ($searchKeys !== [] && ! $this->matchesSearchInRow($row, $searchKeys)) {
            return false;
        }

        $zeroKeys = $config['zero_keys'] ?? [];
        if ($this->hideZero && $zeroKeys !== [] && $this->isZeroRow($row, $zeroKeys)) {
            return false;
        }

        $amountKeys = $config['amount_keys'] ?? [];
        if ($amountKeys !== [] && ($this->minAmount !== null || $this->maxAmount !== null)) {
            $amountMatched = false;
            foreach ($amountKeys as $key) {
                $value = abs((float) ($row[$key] ?? 0));
                if ($value >= 0.005 && $this->matchesAmount($value)) {
                    $amountMatched = true;
                    break;
                }
            }

            if (! $amountMatched) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $keys
     */
    public function matchesSearchInRow(array $row, array $keys): bool
    {
        if ($this->search === null) {
            return true;
        }

        $needle = mb_strtolower($this->search);
        foreach ($keys as $key) {
            $value = mb_strtolower((string) ($row[$key] ?? ''));
            if ($value !== '' && str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $numericKeys
     */
    public function isZeroRow(array $row, array $numericKeys): bool
    {
        foreach ($numericKeys as $key) {
            if (abs((float) ($row[$key] ?? 0)) >= 0.005) {
                return false;
            }
        }

        return true;
    }

    public function matchesAmount(float $value): bool
    {
        if ($this->minAmount !== null && $value < $this->minAmount) {
            return false;
        }

        if ($this->maxAmount !== null && $value > $this->maxAmount) {
            return false;
        }

        return true;
    }

    /**
     * @return array<int, array{label: string, value: string|int|float}>
     */
    public function activeSummary(): array
    {
        $summary = [];

        if ($this->accountType) {
            $summary[] = ['label' => 'Account type', 'value' => ucfirst($this->accountType)];
        }

        if ($this->accountId) {
            $account = ChartOfAccount::query()->find($this->accountId);
            if ($account) {
                $summary[] = ['label' => 'Account', 'value' => $account->code.' — '.$account->name_en];
            }
        }

        if ($this->search) {
            $summary[] = ['label' => 'Search', 'value' => $this->search];
        }

        if ($this->hideZero) {
            $summary[] = ['label' => 'Hide zero', 'value' => 'Yes'];
        }

        if ($this->reservationId) {
            $summary[] = ['label' => 'Reservation #', 'value' => $this->reservationId];
        }

        if ($this->sourceType) {
            $summary[] = ['label' => 'Source', 'value' => $this->sourceType];
        }

        if ($this->operation) {
            $summary[] = ['label' => 'Operation', 'value' => $this->operation];
        }

        if ($this->minAmount !== null) {
            $summary[] = ['label' => 'Min amount', 'value' => $this->minAmount];
        }

        if ($this->maxAmount !== null) {
            $summary[] = ['label' => 'Max amount', 'value' => $this->maxAmount];
        }

        return $summary;
    }

    private static function parseAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }
}
