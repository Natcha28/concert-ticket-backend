<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Seat extends Model 
{
    use HasFactory;

    protected $table = 'seats';
    protected $primaryKey = 'Seat_id';

    // ✅ ปรับแก้ให้เหลือเฉพาะฟิลด์ที่เราต้อง "สั่งบันทึก" เข้าไปเองครับ
    protected $fillable = [
        'Zone_id', 
        'SeatRow', 
        'SeatNo', 
        'SeatStatus'
    ];
}