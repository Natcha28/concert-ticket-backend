<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HallZone extends Model
{
    use HasFactory;

    protected $table = 'hall_zones';      // ชื่อตาราง
    protected $primaryKey = 'HallZone_id'; // ชื่อ PK

    protected $fillable = [
        'Hall_id',
        'zoneName',
        'zoneCapacity'
    ];
}