<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\EventPayment;
use App\Models\Event;
use App\Models\Organizer; // อย่าลืม import Model นี้

class AdminEventController extends Controller
{
    // ----------------------------------------------------------------
    // 1. ดูรายการรอจ่ายเงิน (Pending Payments)
    // ----------------------------------------------------------------
    public function getPendingEvents()
    {
        // ค้นหาคำว่า 'ยังไม่ได้ชำระ'
        $pendingPayments = EventPayment::where('payStatus', 'ยังไม่ได้ชำระ')
            ->join('events', 'event_payments.Event_id', '=', 'events.Event_id')
            ->select(
                'event_payments.EventPayment_id',
                'events.eventName',
                'event_payments.eventAmount',
                'event_payments.payStatus',
                'event_payments.PayDate'
            )
            ->get();
                        
        return response()->json($pendingPayments);
    }

    // ----------------------------------------------------------------
    // 2. อนุมัติการจ่ายเงิน (Approve Deposit) -> เปลี่ยนสถานะงานให้โชว์
    // ----------------------------------------------------------------
    public function approveDeposit(Request $request)
    {
        $request->validate([
            'EventPayment_id' => 'required|integer'
        ]);

        $payment = EventPayment::find($request->EventPayment_id);

        if (!$payment) {
            return response()->json(['message' => 'ไม่พบรายการชำระเงิน'], 404);
        }

        // 1. อัปเดตสถานะการจ่ายเงิน
        $payment->update([
            'payStatus' => 'ชำระเรียบร้อยแล้ว'
        ]);

        // 2. อัปเดตสถานะงาน (Event) ให้พร้อมโชว์หน้าเว็บ
        $event = Event::find($payment->Event_id);
        if ($event) {
            // ❌ เดิม: $event->update(['is_public' => true]); (อันนี้ผิด เพราะไม่มีคอลัมน์นี้)
            // ✅ ใหม่: เปลี่ยนสถานะเป็น 'กำลังจะจัด' ตาม Enum ใน Database
            $event->update(['eventStatus' => 'กำลังจะจัด']);
        }

        return response()->json([
            'message' => 'อนุมัติสำเร็จ! งานคอนเสิร์ตเปิดขายแล้ว',
            'data'    => $payment
        ]);
    }

    // ----------------------------------------------------------------
    // 3. อนุมัติผู้จัดงาน (Approve Organizer) -> ลบคำว่า WAITING_ ออก
    // ----------------------------------------------------------------
    public function approveOrganizer(Request $request)
    {
        $request->validate(['Org_id' => 'required|integer']);

        $organizer = Organizer::find($request->Org_id);

        if (!$organizer) {
            return response()->json(['message' => 'ไม่พบข้อมูลผู้จัดงาน'], 404);
        }

        // 🟡 วิชามาร: ลบคำว่า "WAITING_" ออกจาก firstnameOG
        // (เปลี่ยนจาก organizerName เป็น firstnameOG ตาม Database จริง)
        $realName = str_replace('WAITING_', '', $organizer->firstnameOG);
        
        $organizer->update([
            'firstnameOG' => $realName
        ]);

        return response()->json(['message' => 'อนุมัติผู้จัดงานเรียบร้อยแล้ว ผู้จัดงานสามารถ Login ได้ทันที']);
    }
}