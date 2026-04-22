<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingDetail extends Model
{
    use HasFactory;

    // 1. ชื่อตาราง
    protected $table = 'booking_details';

    // 2. คีย์หลัก (จากรูปภาพคือ Bdetail_id)
    protected $primaryKey = 'Bdetail_id';

    // 3. ชื่อคอลัมน์ที่อนุญาตให้บันทึก (ต้องตรงกับในรูปเป๊ะๆ)
    protected $fillable = [
        'Booking_id',
        'Seat_id',          // เผื่อไว้ (แม้ตอนนี้จะส่ง null ก็ตาม)
        'QRCode',
        'issueDate',
        'linkDownload',
        'Price_Per_Ticket', // ⚠️ แก้จาก ticketPrice เป็นตัวนี้ตามรูป
        'ETStatus'
    ];

    // ✅ แก้ไข: เอาโค้ดที่ลอยๆ มาใส่ในฟังก์ชันให้ถูกต้อง (เปลี่ยนเป็น belongsTo เพราะมันคือ Detail ของ Booking)
    public function booking()
    {
        return $this->belongsTo(Booking::class, 'Booking_id', 'Booking_id');
    }
}