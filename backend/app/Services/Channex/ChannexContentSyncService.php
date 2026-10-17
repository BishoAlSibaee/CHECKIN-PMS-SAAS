<?php

namespace App\Services\Channex;

use App\Models\ChannexProperty;
use App\Models\ChannexRatePlanMapping;
use App\Models\ChannexRoomTypeMapping;
use App\Models\Hotel;
use App\Models\OtaRatePlan;
use App\Models\RoomType;
use Illuminate\Support\Facades\DB;

/**
 * Creates/updates a hotel's Channex property, its room types, and its OTA
 * rate plans, writing the mapping tables that every other Channex service
 * (ARI push, booking ingestion) reads from.
 */
class ChannexContentSyncService
{
    public function __construct(private ChannexClient $client) {}

    public function syncHotel(int $hotelId): ChannexProperty
    {
        $hotel = Hotel::findOrFail($hotelId);
        $mapping = ChannexProperty::where('hotel_id', $hotelId)->first();

        $attributes = [
            'title' => $hotel->name_en,
            'currency' => 'SAR',
            'email' => (string) config('mail.from.address', 'noreply@example.com'),
            'city' => $hotel->name_en,
            'country' => 'SA',
            'timezone' => 'Asia/Riyadh',
        ];

        if ($mapping === null) {
            $response = $this->client->createProperty($attributes);
            $channexPropertyId = (string) $response['data']['id'];

            $mapping = ChannexProperty::create([
                'hotel_id' => $hotelId,
                'channex_property_id' => $channexPropertyId,
                'last_synced_at' => now(),
            ]);
        } else {
            $this->client->updateProperty($mapping->channex_property_id, ['title' => $hotel->name_en]);
            $mapping->update(['last_synced_at' => now()]);
        }

        return $mapping;
    }

    /**
     * Room types are a shared catalog across hotels, but each hotel's
     * Channex property needs its own room-type entry — count_of_rooms
     * reflects THIS hotel's physical inventory of that type.
     */
    public function syncRoomType(int $hotelId, int $roomTypeId): ChannexRoomTypeMapping
    {
        $property = ChannexProperty::where('hotel_id', $hotelId)->first();
        if ($property === null) {
            $property = $this->syncHotel($hotelId);
        }

        $roomType = RoomType::findOrFail($roomTypeId);
        $countOfRooms = (int) DB::table('rooms')
            ->join('buildings', 'buildings.id', '=', 'rooms.building_id')
            ->where('buildings.hotel_id', $hotelId)
            ->where('rooms.room_type_id', $roomTypeId)
            ->where('rooms.active', 1)
            ->count();

        $mapping = ChannexRoomTypeMapping::where('hotel_id', $hotelId)->where('room_type_id', $roomTypeId)->first();

        $attributes = [
            'property_id' => $property->channex_property_id,
            'title' => $roomType->name_en,
            'count_of_rooms' => max(1, $countOfRooms),
            'occ_adults' => 2,
            'occ_children' => 0,
            'occ_infants' => 0,
            'default_occupancy' => 1,
            'room_kind' => 'room',
        ];

        if ($mapping === null) {
            $response = $this->client->createRoomType($attributes);
            $channexRoomTypeId = (string) $response['data']['id'];

            $mapping = ChannexRoomTypeMapping::create([
                'hotel_id' => $hotelId,
                'room_type_id' => $roomTypeId,
                'channex_room_type_id' => $channexRoomTypeId,
                'last_synced_at' => now(),
            ]);
        } else {
            $this->client->updateRoomType($mapping->channex_room_type_id, [
                'title' => $attributes['title'],
                'count_of_rooms' => $attributes['count_of_rooms'],
            ]);
            $mapping->update(['last_synced_at' => now()]);
        }

        return $mapping;
    }

    public function syncRatePlan(OtaRatePlan $ratePlan): ChannexRatePlanMapping
    {
        $roomTypeMapping = ChannexRoomTypeMapping::where('hotel_id', $ratePlan->hotel_id)
            ->where('room_type_id', $ratePlan->room_type_id)
            ->first();
        if ($roomTypeMapping === null) {
            $roomTypeMapping = $this->syncRoomType((int) $ratePlan->hotel_id, (int) $ratePlan->room_type_id);
        }

        $mapping = ChannexRatePlanMapping::where('ota_rate_plan_id', $ratePlan->id)->first();

        $attributes = [
            'property_id' => ChannexProperty::where('hotel_id', $ratePlan->hotel_id)->value('channex_property_id'),
            'room_type_id' => $roomTypeMapping->channex_room_type_id,
            'title' => $ratePlan->name_en,
            'currency' => 'SAR',
            'sell_mode' => 'per_room',
            'rate_mode' => 'manual',
            // A placeholder default — day-by-day pricing is set for real via
            // the ARI push (POST /restrictions, Phase 4), not here.
            'options' => [
                ['occupancy' => 1, 'rate' => '0.00', 'is_primary' => true],
            ],
        ];

        if ($mapping === null) {
            $response = $this->client->createRatePlan($attributes);
            $channexRatePlanId = (string) $response['data']['id'];

            $mapping = ChannexRatePlanMapping::create([
                'ota_rate_plan_id' => $ratePlan->id,
                'channex_rate_plan_id' => $channexRatePlanId,
                'last_synced_at' => now(),
            ]);
        } else {
            $mapping->update(['last_synced_at' => now()]);
        }

        return $mapping;
    }
}
