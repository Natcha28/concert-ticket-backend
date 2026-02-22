<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\TicketZone;
use App\Models\BookingDetail; // ✅ Import Model นี้
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB; // ✅ Import DB เพื่อใช้ Transaction

class BookingController extends Controller
{
    // =================================================================
    // ดูรายการจองของฉัน (My Tickets)
    // =================================================================
    public function index(Request $request)
    {
        $myTickets = Booking::where('Mem_id', $request->user()->Mem_id)
            ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
            ->join('events', 'event_date_times.Event_id', '=', 'events.Event_id') 
            ->join('ticket_zones', 'bookings.Zone_id', '=', 'ticket_zones.Zone_id')
            ->select(
                'bookings.Booking_id',
                'events.eventName',          // ชื่อคอนเสิร์ต
                'event_date_times.startDT',  // วันเวลาแสดง
                'ticket_zones.zoneName',     // ชื่อโซน
                'bookings.quantity',         // จำนวน
                'bookings.totalPrice',       // ราคา
                'bookings.BKStatus'          // สถานะ
            )
            ->orderBy('bookings.created_at', 'desc')
            ->get();

        return response()->json($myTickets);
    }

    // =================================================================
    // จองบัตร (Store) - ฉบับสมบูรณ์ 100% (แก้ไขชื่อตัวแปรแล้ว)
    // =================================================================
    public function store(Request $request)
    {
        $request->validate([
            'Datetime_id' => 'required|integer',
            'Zone_id'     => 'required|integer',
            'ticketQty'   => 'required|integer|min:1'
        ]);

        // ✅ ใช้ Transaction: รับประกันว่าตัดเงิน สร้างบิล สร้างรายละเอียด ต้องสำเร็จพร้อมกัน
        return DB::transaction(function () use ($request) {
            
            // 1. ล็อกแถวข้อมูลโซนเพื่อป้องกันการแย่งกันกด (Lock Row)
            $zone = TicketZone::where('Zone_id', $request->Zone_id)->lockForUpdate()->first();
            
            if (!$zone) {
                return response()->json(['message' => 'ไม่พบข้อมูลโซนบัตร'], 404);
            }

            // 2. เช็คที่นั่งว่าง
            if ($zone->remainSeat < $request->ticketQty) {
                return response()->json(['message' => 'ที่นั่งไม่พอ'], 400);
            }

            // 3. คำนวณราคารวม
            $calculatedTotalPrice = $zone->priceperTick * $request->ticketQty;

            // 4. สร้างหัวบิล (Bookings)
            $booking = Booking::create([
                'Mem_id'      => $request->user()->Mem_id,  
                'Datetime_id' => $request->Datetime_id,
                'Zone_id'     => $request->Zone_id,
                'quantity'    => $request->ticketQty,
                'totalPrice'  => $calculatedTotalPrice,
                'BKDate'      => now(),
                'BKStatus'    => 'รอการชำระเงิน' 
            ]);

            // 5. ✅ [แก้ไขจุดสำคัญ] สร้างรายละเอียดบิล (Booking Details)
            // วนลูปสร้างตามจำนวนตั๋วที่ซื้อ (เช่น ซื้อ 3 ใบ ก็สร้าง 3 แถว)
            for ($i = 0; $i < $request->ticketQty; $i++) {
                BookingDetail::create([
                    'Booking_id'       => $booking->Booking_id,
                    
                    // ✅ แก้ไข: ใช้ชื่อคอลัมน์ให้ตรงกับ Database จริง
                    'Price_Per_Ticket' => $zone->priceperTick, 
                    
                    // ✅ เพิ่ม: สถานะเริ่มต้นและวันที่ออกตั๋ว
                    'ETStatus'         => 'ใช้งานได้', 
                    'issueDate'        => now()
                ]);
            }

            // 6. ตัดยอดที่นั่งคงเหลือ
            $zone->decrement('remainSeat', $request->ticketQty);

            return response()->json([
                'message' => 'จองบัตรสำเร็จ!',
                'data'    => $booking
            ], 201);
        });
    }
}