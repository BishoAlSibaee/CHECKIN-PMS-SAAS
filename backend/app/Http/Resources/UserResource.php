<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'job_number'      => $this->job_number,
            'mobile'          => $this->mobile,
            'email'           => $this->email,
            'active'          => $this->active,

            // العلاقات
            'jobtitle_id'     => $this->jobtitle_id,
            'jobtitle_name'   => $this->jobtitle?->name_en ?? $this->jobtitle?->name_ar,
            'department_id'   => $this->department_id,
            'department_name' => $this->department?->name_en ?? $this->department?->name_ar,
            'discount_id'     => $this->discount_id,
            'discount_name'   => $this->discount?->name,

            // الصلاحيات والأدوار
            'permissions'     => $this->permissions->pluck('permission'),
            'roles'           => $this->roles->pluck('name'),

            // التواريخ
            'created_at'      => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at'      => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
