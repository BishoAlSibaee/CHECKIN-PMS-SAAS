<?php

namespace App\Http\Resources\Building;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Floors\FloorResource;
use Carbon\Carbon;
class BuildingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return[
            'id'=>$this->id,
            'name'=>$this->name,
            'created_at'=>Carbon::parse($this->created_at)->format('Y-m-d H:i'),
            'updated_at'=>Carbon::parse($this->updated_at)->format('Y-m-d H:i'),
            'floors'=> FloorResource::collection($this->floors),

        ];
    }
}
