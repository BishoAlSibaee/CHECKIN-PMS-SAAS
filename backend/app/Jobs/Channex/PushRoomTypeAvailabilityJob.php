<?php

namespace App\Jobs\Channex;

use App\Services\Channex\ChannexAriPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Fire-and-forget — a Channex outage must never block the booking/cancel/
 * extend/shorten action that triggered this push. Debounced per (hotel,
 * room_type): several bookings on the same room type within the window
 * collapse into one push instead of one Channex API call per booking.
 */
class PushRoomTypeAvailabilityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const DEBOUNCE_SECONDS = 30;

    public int $tries = 3;

    public function __construct(
        private readonly int $hotelId,
        private readonly int $roomTypeId,
    ) {}

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /** Call this instead of dispatch() directly so repeated triggers within the debounce window collapse into one push. */
    public static function dispatchDebounced(int $hotelId, int $roomTypeId): void
    {
        $key = "channex_ari_push_debounce:{$hotelId}:{$roomTypeId}";
        if (! Cache::add($key, true, self::DEBOUNCE_SECONDS)) {
            return;
        }

        static::dispatch($hotelId, $roomTypeId);
    }

    public function handle(ChannexAriPushService $service): void
    {
        $service->pushForRoomType($this->hotelId, $this->roomTypeId);
    }
}
