<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory;

    protected $table = 'bookings';
    protected $primaryKey = 'Booking_id'; // PK ของคุณชื่อนี้
    
    // สำคัญมาก! ต้องมีบรรทัดนี้ ไม่งั้นบันทึกไม่ลง
    protected $fillable = [
        'Mem_id', 
        'Datetime_id', 
        'Zone_id', 
        'BKDate', 
        'quantity', 
        'totalPrice', 
        'BKStatus'
    ];

    // ✅ เพิ่มส่วนนี้เข้าไป เพื่อให้รู้ว่า 1 Booking มีหลาย BookingDetail
    public function booking_details()
    {
        return $this->hasMany(BookingDetail::class, 'Booking_id', 'Booking_id');
    }
}