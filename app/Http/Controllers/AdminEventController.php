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

        // =================================================================
    // ดึงข้อมูลสมาชิก (เวอร์ชันใส่เกราะกันข้อมูลแปลกปลอม)
    // =================================================================
    public function getMembers()
    {
        try {
            $members = \Illuminate\Support\Facades\DB::table('members')->get();
            $formattedMembers = [];

            foreach ($members as $member) {
                // 1. ดึงข้อมูลการจอง 
                $bookings = \Illuminate\Support\Facades\DB::table('bookings')
                    ->where('Mem_id', $member->Mem_id)
                    ->leftJoin('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                    ->leftJoin('events', 'event_date_times.Event_id', '=', 'events.Event_id')
                    ->leftJoin('ticket_zones', 'bookings.Zone_id', '=', 'ticket_zones.Zone_id')
                    ->select(
                        'bookings.Booking_id', 'bookings.totalPrice', 'bookings.quantity', 'bookings.BKStatus',
                        'events.eventName', 'events.bannerImage',
                        'event_date_times.startDT',
                        'ticket_zones.zoneName'
                    )
                    ->get();

                $orders = [];
                foreach ($bookings as $booking) {
                    // 2. ดึงข้อมูลที่นั่ง
                    $seats = \Illuminate\Support\Facades\DB::table('booking_details')
                        ->where('Booking_id', $booking->Booking_id)
                        ->leftJoin('seats', 'booking_details.Seat_id', '=', 'seats.Seat_id')
                        ->select('seats.SeatRow', 'seats.SeatNo', 'booking_details.Seat_id')
                        ->get();

                    $seatArr = [];
                    foreach ($seats as $s) {
                        if (!empty($s->SeatRow) && !empty($s->SeatNo)) {
                            $seatArr[] = trim($s->SeatRow) . trim($s->SeatNo);
                        } else {
                            $seatArr[] = $s->Seat_id;
                        }
                    }

                    $orders[] = [
                        'id' => 'ORD-' . str_pad($booking->Booking_id, 4, '0', STR_PAD_LEFT),
                        // บังคับเข้ารหัสให้ปลอดภัย ป้องกัน Malformed UTF-8 
                        'event' => mb_convert_encoding($booking->eventName ?? '-', 'UTF-8', 'UTF-8'),
                        'seat' => mb_convert_encoding(($booking->zoneName ?? '-') . ' (' . $booking->quantity . ' ใบ)', 'UTF-8', 'UTF-8'),
                        'seatNumber' => !empty($seatArr) ? implode(', ', $seatArr) : '-',
                        'price' => (float) $booking->totalPrice,
                        'date' => !empty($booking->startDT) ? \Carbon\Carbon::parse($booking->startDT)->format('d M Y') : '-',
                        'time' => !empty($booking->startDT) ? \Carbon\Carbon::parse($booking->startDT)->format('H:i') : '-',
                        'venue' => 'สถานที่จัดงาน (ระบบอัตโนมัติ)',
                        'status' => ($booking->BKStatus === 'ชำระเงินแล้ว') ? 'Paid' : (($booking->BKStatus === 'ยกเลิก') ? 'Cancelled' : 'Used'),
                        // หากรูปเป็น Binary ให้ทิ้งไปเลย ระบบจะได้ไม่พัง
                        'poster' => mb_check_encoding($booking->bannerImage, 'UTF-8') ? $booking->bannerImage : ''
                    ];
                }

                $firstName = $member->firstnameMB ?? '';
                $lastName = $member->lastnameMB ?? '';
                $fullName = trim($firstName . ' ' . $lastName);
                $accountStatus = (isset($member->statusMB) && $member->statusMB === 'ใช้งานได้') ? 'Active' : 'Banned';

                $formattedMembers[] = [
                    'id' => $member->Mem_id,
                    'name' => mb_convert_encoding(!empty($fullName) ? $fullName : 'Unknown', 'UTF-8', 'UTF-8'),
                    'email' => mb_convert_encoding($member->emailMB ?? '-', 'UTF-8', 'UTF-8'),
                    'role' => 'Member',
                    'status' => $accountStatus,
                    'joined' => !empty($member->created_at) ? \Carbon\Carbon::parse($member->created_at)->format('d M Y') : '-',
                    'spent' => collect($orders)->where('status', 'Paid')->sum('price'),
                    'avatar' => strtoupper(substr(!empty($fullName) ? mb_convert_encoding($fullName, 'UTF-8', 'UTF-8') : 'U', 0, 1)),
                    'orders' => $orders
                ];
            }

            // 🟢 จุดสำคัญ: สั่งข้ามอักขระขยะ (JSON_INVALID_UTF8_SUBSTITUTE) ห้ามระเบิด 500 อีก!
            return response()->json(
                $formattedMembers, 
                200, 
                [], 
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );

        } catch (\Exception $e) {
            return response()->json([
                [
                    'id' => 999,
                    'name' => '🚨 สรุป Error คือ: ' . $e->getMessage(),
                    'email' => 'บรรทัดที่ ' . $e->getLine(),
                    'role' => 'Member',
                    'status' => 'Banned',
                    'joined' => '-',
                    'spent' => 0,
                    'avatar' => '❌',
                    'orders' => []
                ]
            ], 200); 
        }
    }

    // =================================================================
    // ดึงข้อมูลอีเวนต์ทั้งหมด (สำหรับหน้าจัดการอีเวนต์ของ Admin)
    // =================================================================
    public function getAllEvents()
    {
        try {
            // 1. ดึงงานทั้งหมด พร้อมจอยชื่อผู้จัดและสถานที่
            $events = \Illuminate\Support\Facades\DB::table('events')
                ->leftJoin('organizers', 'events.Org_id', '=', 'organizers.Org_id')
                ->leftJoin('halls', 'events.Hall_id', '=', 'halls.Hall_id')
                ->select(
                    'events.*',
                    'organizers.firstnameOG',
                    'organizers.lastnameOG',
                    'halls.Hall_Name'
                )
                ->orderBy('events.created_at', 'desc')
                ->get();

            $formattedEvents = [];

            foreach ($events as $e) {
                // 2. ดึงข้อมูลวัน-เวลาแสดง (เอาแค่รอบแรกมาโชว์)
                $dt = \Illuminate\Support\Facades\DB::table('event_date_times')
                    ->where('Event_id', $e->Event_id)
                    ->first();

                // 3. ดึงข้อมูลโซนบัตรและราคา
                $tickets = \Illuminate\Support\Facades\DB::table('ticket_zones')
                    ->where('Event_id', $e->Event_id)
                    ->get();

                $ticketArr = [];
                foreach ($tickets as $t) {
                    $ticketArr[] = [
                        'id' => $t->Zone_id,
                        'zone' => $t->zoneName,
                        'price' => (float) $t->priceperTick,
                        'totalSeats' => (int) $t->totalSeat
                    ];
                }

                // 4. แปลงสถานะใน DB ให้เข้ากับ UI ของ React (แก้ขัดไปก่อน)
                $dbStatus = $e->eventStatus;
                $frontendStatus = 'pending'; // ค่าเริ่มต้น
                if (in_array($dbStatus, ['กำลังจะจัด', 'เปิดขายบัตร', 'รอชำระเงิน' ,'เปิดขาย', 'UPCOMING', 'Selling', 'On Sale', 'บัตรขายหมด'])) {
                    $frontendStatus = 'approved';
                } elseif (in_array($dbStatus, ['ยกเลิก', 'ยกเลิกงาน'])) {
                    $frontendStatus = 'cancelled';
                }

                // 5. จัดรูปแบบข้อมูลให้ตรงกับ Interface EventType ใน React
                $formattedEvents[] = [
                    'id' => $e->Event_id,
                    'title' => mb_convert_encoding($e->eventName ?? 'ไม่ได้ระบุชื่องาน', 'UTF-8', 'UTF-8'),
                    'organizer' => trim(($e->firstnameOG ?? 'Unknown') . ' ' . ($e->lastnameOG ?? '')),
                    'date' => ($dt && $dt->startDT) ? \Carbon\Carbon::parse($dt->startDT)->format('d M Y') : 'ไม่ระบุ',
                    'endDate' => ($dt && $dt->endDT) ? \Carbon\Carbon::parse($dt->endDT)->format('d M Y') : '',
                    'time' => ($dt && $dt->startDT) ? \Carbon\Carbon::parse($dt->startDT)->format('H:i') : 'ไม่ระบุ',
                    'venue' => mb_convert_encoding($e->Hall_Name ?? 'ไม่ระบุสถานที่', 'UTF-8', 'UTF-8'),
                    // เช็คว่ารูปเป็น Base64 หรือลิงก์ ถ้ามีปัญหาให้ส่งค่าว่างไปก่อน
                    'image' => mb_check_encoding($e->bannerImage, 'UTF-8') ? $e->bannerImage : '',
                    'description' => mb_convert_encoding($e->eventDescription ?? '', 'UTF-8', 'UTF-8'),
                    'category' => 'Concert', // ใน DB ไม่มี category ขอใส่ตายตัวไว้ก่อน
                    'approvalStatus' => $frontendStatus, // แมปสถานะแล้ว
                    'paymentStatus' => 'paid',
                    'publishDate' => $e->created_at ? \Carbon\Carbon::parse($e->created_at)->format('d M Y') : 'ไม่ระบุ',
                    'saleStartDate' => ($dt && $dt->Sale_startDT) ? \Carbon\Carbon::parse($dt->Sale_startDT)->format('d M Y, H:i') : '',
                    'depositAmount' => '0',
                    'tickets' => $ticketArr
                ];
            }

            return response()->json($formattedEvents, 200, [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error: ' . $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }


     public function updateStatus(Request $request, $id)
{
    // ใช้ Event_id ตามโครงสร้างโต๊ะของคุณ (ถ้า Model ตั้งค่า primaryKey ไว้แล้วใช้ find ได้เลย)
    $event = \App\Models\Event::where('Event_id', $id)->firstOrFail();

    $status = $request->approvalStatus;
    $dbStatus = $status; // ค่าเริ่มต้น

    // แปลงจากค่าที่ React ส่งมา เป็นค่าที่ DB เข้าใจ
    if ($status === 'approved') {
        $dbStatus = 'รอชำระเงิน'; 
    } elseif ($status === 'rejected') {
        $dbStatus = 'ไม่อนุมัติ';
    } elseif ($status === 'changes_requested') {
        $dbStatus = 'รอแก้ไข';
    }

    $event->update([
        // เปลี่ยนชื่อคอลัมน์ให้ตรงกับ DB ของคุณ (สมมติว่าใช้ eventStatus)
        'eventStatus' => $dbStatus, 
        'adminFeedback' => $request->adminFeedback
    ]);

    return response()->json([
        'message' => 'อัปเดตสถานะเป็น ' . $dbStatus . ' เรียบร้อยแล้ว',
        'event' => $event
    ]);
}





    }