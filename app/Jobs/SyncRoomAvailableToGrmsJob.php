<?php

namespace App\Jobs;

use App\Services\GrmsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the Guest Room Management System a room is ready for check-in again after
 * housekeeping cleans it (GRMS's setRoomAvailableApi). Fire-and-forget — a GRMS
 * outage must never block the staff action that marks a room ready.
 */
class SyncRoomAvailableToGrmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // TEMPORARY: GRMS requires a housekeeping job id we don't track yet — always send
    // "201" per instruction until that's wired up on our side.
    private const PLACEHOLDER_JOB_NUMBER = '201';

    public int $tries = 5;

    public function __construct(
        private readonly string $roomNumber,
    ) {}

    public function backoff(): array
    {
        return [10, 30, 60, 300, 900];
    }

    public function handle(GrmsClient $grms): void
    {
        if (!$grms->isEnabled()) {
            return;
        }

        $grmsRoomNumber = GrmsClient::normalizeRoomNumber($this->roomNumber);

        if ($grmsRoomNumber === '') {
            return;
        }

        $grms->setRoomAvailable([
            'room_number' => $grmsRoomNumber,
            'jobNumber' => self::PLACEHOLDER_JOB_NUMBER,
        ]);
    }
}
