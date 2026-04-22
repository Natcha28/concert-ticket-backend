<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Payment;
use App\Models\Booking; // เรียกใช้ Model Booking ด้วย เพื่อไปแก้สถานะ
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class PaymentController extends Controller
{
    public function store(Request $request)
    {
        // 1. รับค่า (เลข Booking และ ยอดเงิน)
        $request->validate([
            'Booking_id' => 'required|integer',
            'Amount'     => 'required|integer'
        ]);

        // 2. เช็คว่าเป็นเจ้าของ Booking จริงไหม (กันคนมั่ว)
        $user = Auth::user();
        $booking = Booking::where('Booking_id', $request->Booking_id)
                          ->where('Mem_id', $user->Mem_id)
                          ->first();

        if (!$booking) {
            return response()->json(['message' => 'ไม่พบข้อมูลการจอง หรือคุณไม่ใช่เจ้าของรายการนี้'], 404);
        }

        // 3. บันทึกข้อมูลลงตาราง payments
        // *** แก้บรรทัดนี้ครับ: ต้องใช้คำว่า 'ชำระเรียบร้อยแล้ว' ตามกฎ Database ***
        $payment = Payment::create([
            'Booking_id' => $request->Booking_id,
            'PMDate'     => Carbon::now(),
            'Amount'     => $request->Amount,
            'PMStatus'   => 'ชำระเรียบร้อยแล้ว' 
        ]);

        // 4. อัปเดตสถานะในตาราง bookings 
        // (Booking ใช้คำว่า 'ชำระเงินแล้ว' เหมือนเดิมได้เลยครับ ถ้า Database ฝั่งนั้นไม่ได้ห้าม)
        $booking->update([
            'BKStatus' => 'ชำระเงินแล้ว'
        ]);

        return response()->json([
            'message' => 'ชำระเงินเรียบร้อย!',
            'data'    => $payment
        ], 201);
    }


    // =================================================================
    // 2. ฟังก์ชันของฝั่งผู้จัดงาน (Organizer) -> สร้าง QR Code เปิดระบบ
    // =================================================================
    public function generatePromptPay(Request $request)
    {
        // รับยอดเงินที่ส่งมาจาก Frontend (หน้า React)
        $amount = $request->input('amount', 1500);
        
        // 🛑 เปลี่ยนเบอร์นี้เป็นเบอร์โทรศัพท์ (หรือเลขบัตร ปชช) ที่ผูก PromptPay ของคุณ
        $promptpayNumber = "0812345678"; 

        // ใช้ API ฟรีในการสร้างรูปรหัส QR Code 
        $qrCodeUrl = "https://promptpay.io/{$promptpayNumber}/{$amount}.png";

        return response()->json([
            'success' => true,
            'qr_code' => $qrCodeUrl,
            'message' => 'สร้าง QR Code สำเร็จ'
        ]);
    }

    // =================================================================
    // 3. ฟังก์ชันจำลองการชำระเงินเปิดระบบ (อัปเดตสถานะงาน)
    // =================================================================
    public function publishPayment(Request $request, $id)
    {
        // 1. ค้นหางานคอนเสิร์ต
        $event = \App\Models\Event::with('event_date_times')->where('Event_id', $id)->first();
        
        if (!$event) {
            return response()->json(['message' => 'ไม่พบข้อมูลอีเวนต์'], 404);
        }

        // 2. คำนวณสถานะใหม่ โดยอิงจากวันที่ปัจจุบัน เทียบกับ วันเปิดขายบัตร
        $now = Carbon::now();
        $newStatus = 'กำลังจะจัด'; // ค่าเริ่มต้นถ้ายังไม่ถึงวันขาย

        $firstRound = $event->event_date_times->sortBy('Sale_startDT')->first();
        if ($firstRound && $firstRound->Sale_startDT) {
            $saleStart = Carbon::parse($firstRound->Sale_startDT);
            // ถัาวินาทีที่กดจ่ายเงิน ดันเป็นเวลาที่ถึงกำหนดขายบัตรพอดี ก็ให้เป็น "เปิดขาย" เลย
            if ($now->greaterThanOrEqualTo($saleStart)) {
                $newStatus = 'เปิดขาย'; 
            }
        }

        // 3. อัปเดตข้อมูลลง Database
        $event->update([
            'eventStatus' => $newStatus
        ]);

        return response()->json([
            'success' => true,
            'message' => 'ชำระเงินและเปิดระบบสำเร็จ',
            'newStatus' => $newStatus
        ]);
    }

}