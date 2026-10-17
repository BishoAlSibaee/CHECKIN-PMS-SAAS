<?php

namespace App\Http\Resources\Login;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoginResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            "id"=> $this->id,
            'name' => $this->name,
            'job_number'=>$this->job_number,
        'jobtitle_id'=>$this->jobtitle_id,
        'department_id'=>$this->department_id,
        'discount'=>$this->discount_id,
        'mobile'=>$this->mobile,
        'email'=>$this->email,
        'permissions' => $this->getAllPermissions()->pluck('name')->values()->all(),
        'roles' => $this->getRoleNames()->values()->all(),
        'role' => $this->getRoleNames()->first(),
        'available_hotels' => $this->availableHotels,
        'current_hotel_id' => $this->currentHotelId,
        'token'=>$this->token->accessToken,
        'token_expire_at'=>$this->token->token?->expires_at
            ? Carbon::parse($this->token->token->expires_at)->format('Y-m-d H:i:s')
            : null,
        ];
    }
}
