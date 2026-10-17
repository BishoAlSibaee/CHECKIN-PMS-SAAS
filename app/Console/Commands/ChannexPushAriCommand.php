<?php

namespace App\Console\Commands;

use App\Models\ChannexRoomTypeMapping;
use App\Services\Channex\ChannexAriPushService;
use Illuminate\Console\Command;
use Throwable;

class ChannexPushAriCommand extends Command
{
    protected $signature = 'channex:push-ari';

    protected $description = 'Nightly full resync of availability/rates to Channex for every connected hotel/room-type';

    public function handle(ChannexAriPushService $service): int
    {
        $mappings = ChannexRoomTypeMapping::all(['hotel_id', 'room_type_id']);

        $failures = 0;
        foreach ($mappings as $mapping) {
            try {
                $service->pushForRoomType((int) $mapping->hotel_id, (int) $mapping->room_type_id);
                $this->info("Pushed hotel #{$mapping->hotel_id} / room type #{$mapping->room_type_id}");
            } catch (Throwable $e) {
                $failures++;
                $this->error("Hotel #{$mapping->hotel_id} / room type #{$mapping->room_type_id} failed: {$e->getMessage()}");
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
