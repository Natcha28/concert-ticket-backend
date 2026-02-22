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
}