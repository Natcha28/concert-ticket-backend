<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Event;
use App\Models\EventPayment;
use Carbon\Carbon;

class OrganizerPaymentController extends Controller
{
    public function payDeposit(Request $request)
    {
        $request->validate([
            'Event_id' => 'required|integer',
            'amount'   => 'required|integer',
            'method'   => 'required|string'
        ]);

        $event = Event::find($request->Event_id);
        if (!$event) {
            return response()->json(['message' => 'ไม่พบงานคอนเสิร์ตนี้'], 404);
        }

        // ✅ แก้ตรงนี้: ใช้คำว่า 'ยังไม่ได้ชำระ' ตามที่ Database กำหนด
        // (ไม่ว่าจะโอนหรือจ่ายหน้างาน ก็ถือว่า "ยังไม่ได้ชำระ" จนกว่าแอดมินจะกดอนุมัติ)
        $status = 'ยังไม่ได้ชำระ';

        $payment = EventPayment::create([
            'Event_id'    => $request->Event_id,
            'eventAmount' => $request->amount,
            'PayDate'     => Carbon::now(),
            'payStatus'   => $status
        ]);

        return response()->json([
            'message' => 'บันทึกรายการชำระเงินสำเร็จ',
            'data'    => $payment
        ]);
    }
}