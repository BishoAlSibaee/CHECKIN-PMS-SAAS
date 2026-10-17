<?php

namespace App\Http\Resources\Room;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Building\BuildingResource;
// use App\Http\Resources\FloorResource;
// use App\Http\Resources\SuiteResource;
// use App\Http\Resources\RoomType\RoomTypeResource;

class AvailableRoomResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_number' => $this->room_number,
            'roomStatus' => $this->roomStatus,
            'active' => $this->active,
            'building' => new BuildingResource($this->whenLoaded('building')),
            'floor' => $this->whenLoaded('floor'),
            'suite' => $this->whenLoaded('suite'),
            'room_type' => $this->whenLoaded('roomType'),
            'features' => $this->roomFeatures->pluck('feature'),
        ];
    }
}

