<?php

namespace App\Services\Accounting;

use App\Models\FinancialAuditLog;
use App\Models\JournalEntry;
use App\Models\Reservation;
use App\Models\ReservationPay;
use App\Models\ReservationRoom;
use App\Models\User;

class FinancialAuditService
{
    public function log(string $action, ?string $entityType = null, ?int $entityId = null, array $details = []): FinancialAuditLog
    {
        return FinancialAuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details ?: null,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForPeriod(?string $start, ?string $end, int $limit = 200): array
    {
        return collect($this->manualAuditRows($start, $end))
            ->merge($this->journalActivityRows($start, $end))
            ->sortByDesc(fn (array $row) => $row['created_at'])
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Human-readable rows for the financial-audit-log report.
     *
     * @return array{rows: array<int, array<string, mixed>>, summary: array<int, array{label: string, value: int}>}
     */
    public function listForReport(?string $start, ?string $end, int $limit = 200): array
    {
        $raw = $this->listForPeriod($start, $end, $limit);
        $userIds = collect($raw)->pluck('user_id')->filter()->unique()->values()->all();
        $users = $userIds !== []
            ? User::query()->whereIn('id', $userIds)->get()->keyBy('id')
            : collect();

        $references = collect($raw)
            ->map(fn (array $row) => is_array($row['details']) ? ($row['details']['reference'] ?? null) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $roomNumberByReservationRoomId = $this->roomNumbersForAccrualReferences($references);

        $candidateReservationIds = collect($raw)
            ->map(fn (array $row) => $this->candidateReservationIds($row))
            ->flatten()
            ->filter(fn ($id) => (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $existingReservationIds = $candidateReservationIds === []
            ? []
            : Reservation::query()
                ->whereIn('id', $candidateReservationIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        $existingReservationLookup = array_fill_keys($existingReservationIds, true);

        $rows = array_map(
            fn (array $row) => $this->formatReportRow($row, $users, $roomNumberByReservationRoomId, $existingReservationLookup),
            $raw
        );

        $counts = collect($rows)->countBy('operation');

        return [
            'rows' => $rows,
            'summary' => [
                ['label' => 'Total events', 'value' => count($rows)],
                ['label' => 'Payments', 'value' => (int) ($counts->get('payment_posted', 0))],
                ['label' => 'Refunds', 'value' => (int) ($counts->get('refund_posted', 0))],
                ['label' => 'Accruals', 'value' => (int) ($counts->get('accrual_posted', 0))],
                ['label' => 'Manual journals', 'value' => (int) ($counts->get('journal_entry_created', 0))],
                ['label' => 'Period closes', 'value' => (int) ($counts->get('period_closed', 0))],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function manualAuditRows(?string $start, ?string $end): array
    {
        $query = FinancialAuditLog::query()->orderByDesc('created_at');

        if ($start && $end) {
            $query->whereBetween('created_at', [$start.' 00:00:00', $end.' 23:59:59']);
        }

        return $query->get()->map(function (FinancialAuditLog $row) {
            $reservationId = $row->entity_type === 'reservation' && $row->entity_id
                ? (int) $row->entity_id
                : null;
            $journalEntryId = $row->entity_type === 'journal_entry' && $row->entity_id
                ? (int) $row->entity_id
                : null;

            return [
                'id' => $row->id,
                'user_id' => $row->user_id,
                'action' => $row->action,
                'entity_type' => $row->entity_type,
                'entity_id' => $row->entity_id,
                'journal_entry_id' => $journalEntryId,
                'reservation_id' => $reservationId,
                'details' => $row->details,
                'created_at' => $row->created_at?->toDateTimeString(),
            ];
        })->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function journalActivityRows(?string $start, ?string $end): array
    {
        $query = JournalEntry::query()
            ->whereIn('source_type', ['reservation_pay', 'daily_charge', 'manual'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id');

        if ($start && $end) {
            $query->whereBetween('entry_date', [$start, $end]);
        }

        return $query->with('lines')->get()->map(function (JournalEntry $entry) {
            $reservationId = $entry->lines
                ->pluck('reservation_id')
                ->map(fn ($id) => (int) $id)
                ->first(fn (int $id) => $id > 0);

            if (! $reservationId && $entry->source_type === 'reservation_pay' && (int) $entry->source_id > 0) {
                $reservationId = (int) (ReservationPay::query()->whereKey($entry->source_id)->value('reservation_id') ?: 0);
            }

            if (! $reservationId && is_string($entry->reference) && preg_match('/^PAY-(\d+)/', $entry->reference, $matches) === 1) {
                $reservationId = (int) (ReservationPay::query()->whereKey((int) $matches[1])->value('reservation_id') ?: 0);
            }

            return [
                'id' => 'JE-'.$entry->id,
                'user_id' => $entry->user_id,
                'action' => $this->actionForJournalEntry($entry),
                'entity_type' => $entry->source_type ?? 'journal_entry',
                'entity_id' => $entry->source_id ?? $entry->id,
                'journal_entry_id' => (int) $entry->id,
                'reservation_id' => $reservationId ?: null,
                'details' => [
                    'reference' => $entry->reference,
                    'description' => $entry->description,
                    'entry_date' => $entry->entry_date,
                ],
                'created_at' => $entry->created_at?->toDateTimeString()
                    ?? ($entry->entry_date instanceof \Carbon\Carbon
                        ? $entry->entry_date->toDateTimeString()
                        : (string) $entry->entry_date),
            ];
        })->all();
    }

    /**
     * @param  array<int|string, string|null>  $roomNumberByReservationRoomId
     * @param  array<int, true>  $existingReservationLookup
     * @return array<string, mixed>
     */
    private function formatReportRow(
        array $row,
        $users,
        array $roomNumberByReservationRoomId,
        array $existingReservationLookup = []
    ): array {
        $details = is_array($row['details']) ? $row['details'] : [];
        $reference = (string) ($details['reference'] ?? '');
        $humanReference = AccountingPostingService::humanizeAccrualReference($reference, $roomNumberByReservationRoomId);
        $eventDate = $details['entry_date'] ?? null;
        if ($eventDate instanceof \Carbon\Carbon) {
            $eventDate = $eventDate->toDateString();
        }
        if (! is_string($eventDate) || $eventDate === '') {
            $eventDate = substr((string) ($row['created_at'] ?? ''), 0, 10) ?: '—';
        }

        $reservationId = $this->resolveExistingReservationId($row, $existingReservationLookup);
        $journalEntryId = isset($row['journal_entry_id']) && (int) $row['journal_entry_id'] > 0
            ? (int) $row['journal_entry_id']
            : null;
        $referenceLabel = $humanReference !== '' && $humanReference !== '—' ? $humanReference : '—';
        $detailSlug = $this->resolveDetailSlug($reservationId, $journalEntryId, $referenceLabel);

        return [
            'event_date' => $eventDate,
            'operation' => (string) ($row['action'] ?? 'journal_posted'),
            'description' => $this->describeEvent($row, $details, $reservationId),
            'reference' => $referenceLabel,
            'performed_by' => $this->performerLabel($row['user_id'] ?? null, $users),
            'reservation_id' => $reservationId,
            'journal_entry_id' => $journalEntryId,
            'detail_slug' => $detailSlug,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, int>
     */
    private function candidateReservationIds(array $row): array
    {
        $details = is_array($row['details'] ?? null) ? $row['details'] : [];
        $ids = [];

        if (isset($row['reservation_id']) && (int) $row['reservation_id'] > 0) {
            $ids[] = (int) $row['reservation_id'];
        }

        $fromDescription = $this->extractReservationId($details['description'] ?? '');
        if ($fromDescription) {
            $ids[] = $fromDescription;
        }

        $reference = (string) ($details['reference'] ?? '');
        if (preg_match('/^PAY-(\d+)/', $reference, $matches) === 1) {
            $fromPay = (int) (ReservationPay::query()->whereKey((int) $matches[1])->value('reservation_id') ?: 0);
            if ($fromPay > 0) {
                $ids[] = $fromPay;
            }
        }

        if (($row['entity_type'] ?? null) === 'reservation_pay' && (int) ($row['entity_id'] ?? 0) > 0) {
            $fromSource = (int) (ReservationPay::query()->whereKey((int) $row['entity_id'])->value('reservation_id') ?: 0);
            if ($fromSource > 0) {
                $ids[] = $fromSource;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, true>  $existingReservationLookup
     */
    private function resolveExistingReservationId(array $row, array $existingReservationLookup): ?int
    {
        foreach ($this->candidateReservationIds($row) as $candidateId) {
            if (isset($existingReservationLookup[$candidateId])) {
                return $candidateId;
            }
        }

        return null;
    }

    private function resolveDetailSlug(?int $reservationId, ?int $journalEntryId, string $reference): ?string
    {
        if ($reservationId !== null && $reservationId > 0) {
            return null;
        }

        if ($journalEntryId !== null && $journalEntryId > 0) {
            return 'journal-entries';
        }

        if ($reference !== '' && $reference !== '—') {
            return 'journal-entries';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $details
     */
    private function describeEvent(array $row, array $details, ?int $reservationId = null): string
    {
        $reservationId ??= isset($row['reservation_id']) && (int) $row['reservation_id'] > 0
            ? (int) $row['reservation_id']
            : $this->extractReservationId($details['description'] ?? '');

        return match ($row['action'] ?? '') {
            'payment_posted' => $reservationId
                ? "Payment posted for reservation #{$reservationId}"
                : 'Guest payment posted to ledger',
            'refund_posted' => $reservationId
                ? "Refund posted for reservation #{$reservationId}"
                : 'Guest refund posted to ledger',
            'accrual_posted' => $reservationId
                ? "Room night revenue accrued for reservation #{$reservationId}"
                : 'Room night revenue accrued',
            'period_closed' => isset($details['year'], $details['month'])
                ? sprintf(
                    'Accounting period closed: %04d-%02d',
                    (int) $details['year'],
                    (int) $details['month']
                )
                : 'Accounting period closed',
            'journal_entry_created' => ($details['description'] ?? $details['reference'] ?? '')
                ? (string) ($details['description'] ?? $details['reference'])
                : 'Manual journal entry posted',
            default => (string) ($details['description'] ?? 'Financial event recorded'),
        };
    }

    private function extractReservationId(mixed $description): ?int
    {
        if (! is_string($description) || $description === '') {
            return null;
        }

        if (preg_match('/reservation #(\d+)/i', $description, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function performerLabel(mixed $userId, $users): string
    {
        if ($userId === null || $userId === '' || (int) $userId <= 0) {
            return 'System';
        }

        $user = $users->get((int) $userId);
        if (! $user) {
            return 'User #'.(int) $userId;
        }

        $jobNumber = trim((string) ($user->job_number ?? ''));
        $name = trim((string) ($user->name ?? ''));

        if ($jobNumber !== '' && $name !== '') {
            return "{$jobNumber} — {$name}";
        }

        return $jobNumber !== '' ? $jobNumber : ($name !== '' ? $name : 'User #'.(int) $userId);
    }

    /**
     * @param  array<int, string|null>  $references
     * @return array<int, string|null>
     */
    private function roomNumbersForAccrualReferences(array $references): array
    {
        $reservationRoomIds = [];
        foreach ($references as $reference) {
            if (! is_string($reference)) {
                continue;
            }
            if (preg_match('/^ACCRUAL-RR(\d+)-/', $reference, $matches) !== 1) {
                continue;
            }
            $reservationRoomIds[(int) $matches[1]] = true;
        }

        if ($reservationRoomIds === []) {
            return [];
        }

        return ReservationRoom::query()
            ->with('room:id,number')
            ->whereIn('id', array_keys($reservationRoomIds))
            ->get()
            ->mapWithKeys(fn (ReservationRoom $row) => [
                $row->id => $row->room?->number !== null ? (string) $row->room->number : null,
            ])
            ->all();
    }

    private function actionForJournalEntry(JournalEntry $entry): string
    {
        if ($entry->source_type === 'reservation_pay') {
            return str_contains(strtolower((string) $entry->description), 'refund')
                ? 'refund_posted'
                : 'payment_posted';
        }

        return match ($entry->source_type) {
            'daily_charge' => 'accrual_posted',
            'manual' => 'journal_entry_created',
            default => 'journal_posted',
        };
    }
}
