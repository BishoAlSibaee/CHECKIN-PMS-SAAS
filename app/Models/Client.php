<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    /** Guest master data lives on the secondary database (mysql2 / DB2_*). */
    protected $connection = 'mysql2';

    protected $fillable = [
        'tenant_id',
        'first_name',
        'last_name',
        'email',
        'international_code',
        'mobile',
        'IdType',
        'IdNumber',
        'birth_date',
        'gender',
        'guest_type',
        'nationality',
        'loyalty_points_balance',
        'company_id',
    ];

    public function notes()
    {
        return $this->hasMany(ClientNote::class);
    }

    public function classifications()
    {
        return $this->hasMany(Client_Classifications::class, 'client_id');
    }
}
