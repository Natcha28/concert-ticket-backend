<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\EventPayment;
use App\Models\Event;

class EventPaymentController extends Controller
{
    // ฟังก์ชันจำลองการจ่ายเงินของผู้จัดงาน
    public function simulatePayment(Request $request, $eventId)
    {
        // 1. เช็คว่ามีอีเวนต์นี้อยู่จริงไหม
        $event = Event::where('Event_id', $eventId)->first();
        if (!$event) {
            return response()->json(['message' => 'ไม่พบข้อมูลอีเวนต์'], 404);
        }

        // 2. บันทึกข้อมูลลงตาราง event_payments 
        // ใช้ updateOrCreate เพื่อ: ถ้ายังไม่มีเรคคอร์ดให้สร้างใหม่ แต่ถ้ามีแล้วให้อัปเดต
        $payment = EventPayment::updateOrCreate(
            ['Event_id' => $eventId], // เงื่อนไขค้นหา
            [
                'PayDate' => now(), // เก็บลายนิ้วมือเวลาที่กดจ่ายเงินทันที
                'eventAmount' => 150000, // (ตัวอย่าง) กำหนดค่าเช่าฮอลล์ 
                'payStatus' => 'รอตรวจสอบ' // เปลี่ยนสถานะเพื่อรอให้ Admin มากดอนุมัติ
            ]
        );

        // 3. (Optional) อาจจะเปลี่ยนสถานะ Event กลับไปเป็น 'รอตรวจสอบ' ด้วยก็ได้
        // $event->update(['eventStatus' => 'รอตรวจสอบ']);

        return response()->json([
            'message' => 'จำลองการชำระเงินสำเร็จ เวลาถูกบันทึกเรียบร้อย',
            'payment' => $payment
        ], 200);
    }
}