<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Booking;
use App\Models\TicketZone;
use App\Models\BookingDetail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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
    // จองและล็อกที่นั่ง (Store / Hold Seats)
    // =================================================================
    public function store(Request $request)
    {
        // 1. ตรวจสอบข้อมูลที่ส่งมาจาก Frontend
        $request->validate([
            'Datetime_id' => 'required|integer',
            'Zone_id'     => 'required|integer',
            'seat_ids'    => 'required|array', // รับเป็น Array ของเลขที่นั่ง (Seat_id)
        ]);

        // 2. ใช้ Transaction ป้องกันข้อมูลพังหากเกิด Error ระหว่างทาง
        return DB::transaction(function () use ($request) {
            $seatIds = $request->seat_ids;
            $ticketQty = count($seatIds);
            
            // ดึง ID สมาชิกที่ล็อกอินอยู่ (ถ้ายังไม่ล็อกอิน ให้ค่าเริ่มต้นเป็น 1 เพื่อทดสอบก่อน)
            $userId = Auth::id() ?? 1; 

            // 3. เช็คว่าที่นั่งทั้งหมดที่ส่งมายัง 'ว่าง' อยู่จริงๆ
            $availableSeats = DB::table('seats')
                ->whereIn('Seat_id', $seatIds)
                ->where('SeatStatus', 'ว่าง')
                ->lockForUpdate() // ล็อก Row ไว้ไม่ให้ Request อื่นมาแย่งอ่าน
                ->get();

            if ($availableSeats->count() !== $ticketQty) {
                return response()->json([
                    'message' => 'ขออภัย บางที่นั่งถูกจองหรือกำลังทำรายการอยู่'
                ], 409);
            }

            // 4. อัปเดตสถานะเป็น 'กำลังจอง'
            DB::table('seats')
                ->whereIn('Seat_id', $seatIds)
                ->update([
                    'SeatStatus' => 'กำลังจอง',
                    'updated_at' => now() 
                ]);

            // 5. คำนวณราคา
            $zone = TicketZone::where('Zone_id', $request->Zone_id)
                ->lockForUpdate()
                ->first();
                
            $ticketPrice = $zone->priceperTick; // 👈 ตัวแปรราคาคือ $ticketPrice
            $totalPrice = $ticketPrice * $ticketQty;

            // 6. สร้างบิล (สถานะ 'รอการชำระเงิน') 
            $booking = Booking::create([
                'Mem_id'      => $userId,
                'Datetime_id' => $request->Datetime_id,
                'Zone_id'     => $request->Zone_id,
                'quantity'    => $ticketQty,
                'totalPrice'  => $totalPrice, 
                'BKDate'      => now(),
                'BKStatus'    => 'รอการชำระเงิน' 
            ]);

            // 7. บันทึกรายละเอียดที่นั่งลง BookingDetail
            foreach ($seatIds as $seatId) {
                // ถ้าตอนสร้าง DB คุณตั้งไว้ว่าให้ใช้ 'Pending'
                BookingDetail::create([
                    'Booking_id' => $booking->Booking_id, 
                    'Seat_id' => $seatId,
                    'Price_Per_Ticket' => $ticketPrice,   
                    'ETStatus' => 'รอชำระเงิน',  
                ]);
            }
            
            // 8. ตัดยอดที่นั่งคงเหลือของโซนนั้นๆ
            $zone->decrement('remainSeat', $ticketQty);

            // 9. ส่งข้อมูลการจองสำเร็จกลับไปให้ Frontend
            return response()->json([
                'message'    => 'ล็อกที่นั่งและสร้างรายการจองสำเร็จ',
                'booking_id' => $booking->Booking_id
            ], 201);
        });
    }

    // =================================================================
    // 1. ยืนยันการชำระเงินสำเร็จ (Success Payment)
    // =================================================================
    public function confirmPayment($bookingId)
    {
        return DB::transaction(function () use ($bookingId) {
            $booking = Booking::where('Booking_id', $bookingId)->first();
            if (!$booking) return response()->json(['message' => 'ไม่พบบิลนี้ในระบบ'], 404);

            // 1. อัปเดตสถานะบิลหลัก
            $booking->update(['BKStatus' => 'ชำระเงินแล้ว']);

            // 2. อัปเดตสถานะตั๋วให้พร้อมสแกนเข้างาน
            BookingDetail::where('Booking_id', $bookingId)->update(['ETStatus' => 'ใช้งานได้']);

            // 3. เปลี่ยนสถานะที่นั่งเป็น 'จองแล้ว' ถาวร (คนอื่นกดไม่ได้แล้ว)
            $seatIds = BookingDetail::where('Booking_id', $bookingId)->pluck('Seat_id');
            DB::table('seats')->whereIn('Seat_id', $seatIds)->update(['SeatStatus' => 'จองแล้ว']);

            return response()->json(['message' => 'ชำระเงินสำเร็จ ออกตั๋วเรียบร้อย!']);
        });
    }

    // =================================================================
    // 2. ยกเลิกการจองและคืนที่นั่ง (Cancel Payment)
    // =================================================================
    public function cancelPayment($bookingId)
    {
        return DB::transaction(function () use ($bookingId) {
            $booking = Booking::where('Booking_id', $bookingId)->first();
            if (!$booking) return response()->json(['message' => 'ไม่พบบิลนี้ในระบบ'], 404);

            // 1. เปลี่ยนสถานะบิลหลักเป็น 'ยกเลิก'
            $booking->update(['BKStatus' => 'ยกเลิก']);

            // 2. เปลี่ยนสถานะตั๋วทุกใบเป็น 'ถูกยกเลิก'
            BookingDetail::where('Booking_id', $bookingId)->update(['ETStatus' => 'ถูกยกเลิก']);

            // 3. ⭐️ สำคัญ: คืนเก้าอี้ให้กลับมาเป็น 'ว่าง'
            $seatIds = BookingDetail::where('Booking_id', $bookingId)->pluck('Seat_id');
            DB::table('seats')->whereIn('Seat_id', $seatIds)->update(['SeatStatus' => 'ว่าง']);

            // 4. คืนยอดที่นั่งคงเหลือกลับไปให้โซน
            DB::table('ticket_zones')->where('Zone_id', $booking->Zone_id)->increment('remainSeat', $booking->quantity);

            return response()->json(['message' => 'ยกเลิกรายการและคืนที่นั่งสำเร็จ']);
        });
    }
    // =================================================================
    // ดึงข้อมูลตั๋วเพื่อไปโชว์หน้า E-Ticket (เวอร์ชันจับ Error)
    // =================================================================
    public function getTickets($bookingId)
    {
        try {
            // 1. ดึงข้อมูลหลักของบิล (ตัดตาราง halls ออกก่อน กันพัง)
            $bookingInfo = DB::table('bookings')
                ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                ->join('events', 'event_date_times.Event_id', '=', 'events.Event_id')
                ->join('ticket_zones', 'bookings.Zone_id', '=', 'ticket_zones.Zone_id')
                ->where('bookings.Booking_id', $bookingId)
                ->select(
                    'events.eventName', 
                    'event_date_times.startDT', 
                    'event_date_times.endDT',
                    'ticket_zones.zoneName'
                )->first();

            if (!$bookingInfo) return response()->json(['message' => 'ไม่พบบิล'], 404);

            // 2. ดึงข้อมูลที่นั่งในบิลนั้น
            $seats = DB::table('booking_details')
                ->join('seats', 'booking_details.Seat_id', '=', 'seats.Seat_id')
                ->where('booking_details.Booking_id', $bookingId)
                ->select('seats.SeatRow', 'seats.SeatNo')
                ->get();

            // 3. สร้างรหัสตั๋วแบบสุ่มไปก่อน (หลีกเลี่ยงการเรียก ID ที่อาจจะไม่มี)
            $ticketList = $seats->map(function ($seat, $index) use ($bookingId) {
                return [
                    'seat' => $seat->SeatRow . $seat->SeatNo,
                    'ticketId' => 'TKT-' . str_pad($bookingId, 4, '0', STR_PAD_LEFT) . '-' . str_pad($index + 1, 4, '0', STR_PAD_LEFT)
                ];
            });

            return response()->json([
                'eventName' => $bookingInfo->eventName,
                'eventDate' => \Carbon\Carbon::parse($bookingInfo->startDT)->translatedFormat('l, d F Y'),
                'eventTime' => \Carbon\Carbon::parse($bookingInfo->startDT)->format('H:i') . ' - ' . \Carbon\Carbon::parse($bookingInfo->endDT)->format('H:i') . ' น.',
                'venue' => 'สถานที่จัดงาน (ระบบอัตโนมัติ)', // ฮาร์ดโค้ดไว้ก่อนกันพัง
                'zone' => $bookingInfo->zoneName,
                'tickets' => $ticketList
            ]);

        } catch (\Exception $e) {
            // 🔴 ถ้าพัง มันจะพ่น Error ออกมาบอกตรงนี้เลยครับ!
            return response()->json([
                'message' => 'DB Error: ' . $e->getMessage()
            ], 500);
        }
    }
}