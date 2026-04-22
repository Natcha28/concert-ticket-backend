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
    // 1. ดูรายการจองของฉัน (My Tickets) - เวอร์ชันหุ้มเกราะกัน Error 500
    // =================================================================
    public function index(Request $request)
    {
        try {

            
            $userId = Auth::id(); 

            $myTickets = DB::table('bookings')
                ->where('bookings.Mem_id', $userId)
                ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                ->join('events', 'event_date_times.Event_id', '=', 'events.Event_id') 
                ->select(
                    'bookings.Booking_id as id', 
                    'events.eventName as event_name',        
                    'events.bannerImage as image',           
                    'event_date_times.startDT as date',      
                    'bookings.totalPrice as amount',         
                    'bookings.BKStatus'          
                )
                ->orderBy('bookings.created_at', 'desc')
                ->get();

            // จัดการข้อมูลให้ปลอดภัย ไม่ให้แครชเวลาเจอค่า null
            $formattedTickets = $myTickets->map(function ($ticket) {
                
                // จัดการวันที่
                $formattedDate = 'N/A';
                if (!empty($ticket->date)) {
                    $formattedDate = \Carbon\Carbon::parse($ticket->date)->translatedFormat('d M Y');
                }

                // จัดการรูปภาพ
                $imageUrl = "https://images.unsplash.com/photo-1470225620780-dba8ba36b745?q=80&w=1000";
                if (!empty($ticket->image)) {
                    $imageUrl = str_starts_with($ticket->image, 'http') ? $ticket->image : asset('storage/' . $ticket->image);
                }

                return [
                    'id'           => $ticket->id,
                    'event_name'   => $ticket->event_name,
                    'image'        => $imageUrl,
                    'date'         => $formattedDate,
                    // ทำให้เลข Order ดูเท่ขึ้น เช่น บิล ID 35 จะกลายเป็น ORD-0035
                    'order_number' => 'ORD-' . str_pad($ticket->id, 4, '0', STR_PAD_LEFT), 
                    // ใส่ลูกน้ำให้ราคา
                    'amount'       => number_format($ticket->amount, 0),
                    // ดักสถานะให้ตรงกับ React
                    'status' => ($ticket->BKStatus === 'ชำระเงินแล้ว') ? 'success' : (($ticket->BKStatus === 'ยกเลิก') ? 'failed' : 'pending')
                ];
            });

            return response()->json($formattedTickets, 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Backend Error: ' . $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }

    // =================================================================
    // 2. จองและล็อกที่นั่ง (Store / Hold Seats)
    // =================================================================
    public function store(Request $request)
    {
        $request->validate([
            'Datetime_id' => 'required|integer',
            'Zone_id'     => 'required|integer',
            'seat_ids'    => 'required|array', 
        ]);

        return DB::transaction(function () use ($request) {
            $seatIds = $request->seat_ids;
            $ticketQty = count($seatIds);
            
            
            $userId = Auth::id() ?? 1;

            $availableSeats = DB::table('seats')
                ->whereIn('Seat_id', $seatIds)
                ->where('SeatStatus', 'ว่าง')
                ->lockForUpdate() 
                ->get();

            if ($availableSeats->count() !== $ticketQty) {
                return response()->json([
                    'message' => 'ขออภัย บางที่นั่งถูกจองหรือกำลังทำรายการอยู่'
                ], 409);
            }

            // ⭐️ ดึงข้อมูลโซน
            $zone = TicketZone::where('Zone_id', $request->Zone_id)
                ->lockForUpdate()
                ->first();
                
            // ✅ แก้ไข: เพิ่มการเช็ค ถ้าหาโซนไม่เจอให้หยุดทำงานและบอก Error
            if (!$zone) {
                return response()->json([
                    'message' => 'ไม่พบข้อมูลโซนที่นั่ง ID: ' . $request->Zone_id
                ], 404);
            }

            // ตอนนี้มั่นใจได้แล้วว่า $zone ไม่เป็น null แน่นอน
            $ticketPrice = $zone->priceperTick; 
            $totalPrice = $ticketPrice * $ticketQty;

            // อัปเดตสถานะที่นั่ง
            DB::table('seats')
                ->whereIn('Seat_id', $seatIds)
                ->update([
                    'SeatStatus' => 'รอชำระเงิน', 
                    'updated_at' => now() 
                ]);

            $booking = Booking::create([
                'Mem_id'      => $userId,
                'Datetime_id' => $request->Datetime_id,
                'Zone_id'     => $request->Zone_id,
                'quantity'    => $ticketQty,
                'totalPrice'  => $totalPrice, 
                'BKDate'      => now(),
                'BKStatus'    => 'รอการชำระเงิน' 
            ]);

            foreach ($seatIds as $seatId) {
                BookingDetail::create([
                    'Booking_id' => $booking->Booking_id, 
                    'Seat_id' => $seatId,
                    'Price_Per_Ticket' => $ticketPrice,   
                    'ETStatus' => 'รอชำระเงิน',  
                ]);
            }
            
            $zone->decrement('remainSeat', $ticketQty);

            return response()->json([
                'message'    => 'ล็อกที่นั่งและสร้างรายการจองสำเร็จ',
                'booking_id' => $booking->Booking_id
            ], 201);
        });
    }

    // =================================================================
    // 3. ยืนยันการชำระเงินสำเร็จ ออกตั๋ว และบันทึกยอดเงิน
    // =================================================================
    public function confirmPayment(Request $request, $bookingId)
    {
        $amount = $request->input('amount', 0); 

        return DB::transaction(function () use ($bookingId, $amount) {
            
            // 1. เช็คว่ามีบิลนี้จริงไหม
            $booking = Booking::where('Booking_id', $bookingId)->first();
            if (!$booking) return response()->json(['message' => 'ไม่พบบิลนี้ในระบบ'], 404);

            // 2. อัปเดตสถานะบิลหลัก
            $booking->update(['BKStatus' => 'ชำระเงินแล้ว']);

            // ⭐️ 3. ดึงรายละเอียดบิลมาวนลูปเพื่อสร้าง QRCode และ issueDate ทีละใบ
            $bookingDetails = DB::table('booking_details')
                                ->where('Booking_id', $bookingId)
                                ->orderBy('Bdetail_id', 'asc') // เรียงตาม ID เก้าอี้ใบแรกไปใบสุดท้าย
                                ->get();

            foreach ($bookingDetails as $index => $detail) {
                // สร้างรูปแบบ TKT-0041-0001 (เอา Booking_id มาต่อกับลำดับที่ของตั๋ว)
                $ticketCode = 'TKT-' . str_pad($bookingId, 4, '0', STR_PAD_LEFT) . '-' . str_pad($index + 1, 4, '0', STR_PAD_LEFT);

                DB::table('booking_details')
                    ->where('Bdetail_id', $detail->Bdetail_id)
                    ->update([
                        'ETStatus'  => 'ใช้งานได้',
                        'QRCode'    => $ticketCode, // บันทึกรหัส TKT-XXXX-YYYY
                        'issueDate' => now()        // บันทึกวันเวลาปัจจุบันที่ออกตั๋ว
                    ]);
            }

            // 4. เปลี่ยนสถานะเก้าอี้ในผังให้เป็น 'จองแล้ว'
            $seatIds = $bookingDetails->pluck('Seat_id');
            DB::table('seats')->whereIn('Seat_id', $seatIds)->update(['SeatStatus' => 'จองแล้ว']);

            // 5. บันทึกข้อมูลลงตาราง payments
            DB::table('payments')->insert([
                'Booking_id' => $bookingId,
                'PMDate'     => now(), 
                'Amount'     => $amount,
                'PMStatus'   => 'ชำระเรียบร้อยแล้ว', 
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // ⭐️ สิ่งที่ต้องเพิ่ม: นำโค้ดแจ้งเตือนมาใส่ตรงนี้ ⭐️
           

            return response()->json(['message' => 'ชำระเงินและบันทึกประวัติสำเร็จ ออกตั๋วเรียบร้อย!']);
        });
    }

    // =================================================================
    // 4. ยกเลิกการจองและคืนที่นั่ง (Cancel Payment)
    // =================================================================
    public function cancelPayment($bookingId)
    {
        try {
            return DB::transaction(function () use ($bookingId) {
                // 1. หาบิล
                $booking = DB::table('bookings')->where('Booking_id', $bookingId)->first();
                if (!$booking) {
                    return response()->json(['message' => 'ไม่พบบิลนี้ในระบบ'], 404);
                }

                // 2. เปลี่ยนสถานะบิล
                DB::table('bookings')->where('Booking_id', $bookingId)->update(['BKStatus' => 'ยกเลิก']);
                DB::table('booking_details')->where('Booking_id', $bookingId)->update(['ETStatus' => 'ถูกยกเลิก']);

                // 3. หาที่นั่งในบิลนี้
                $seatIds = DB::table('booking_details')->where('Booking_id', $bookingId)->pluck('Seat_id');
                
                // 4. คืนสถานะที่นั่งให้เป็น 'ว่าง'
                if ($seatIds->count() > 0) {
                    DB::table('seats')->whereIn('Seat_id', $seatIds)->update(['SeatStatus' => 'ว่าง']);
                }

                // 5. คืนโควต้าให้โซน
                DB::table('ticket_zones')->where('Zone_id', $booking->Zone_id)->increment('remainSeat', $booking->quantity);

                return response()->json(['message' => 'ยกเลิกรายการและคืนที่นั่งสำเร็จ']);
            });
        } catch (\Exception $e) {
            // ถ้ามีอะไรพัง จะได้ส่ง Error กลับไปให้หน้าเว็บรู้
            return response()->json(['message' => 'Backend Error: ' . $e->getMessage()], 500);
        }
    }

    // =================================================================
    // 5. ดึงข้อมูลตั๋วเพื่อไปโชว์หน้า E-Ticket
    // =================================================================
    public function getTickets($bookingId)
    {
        try {
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

            $seats = DB::table('booking_details')
                ->join('seats', 'booking_details.Seat_id', '=', 'seats.Seat_id')
                ->where('booking_details.Booking_id', $bookingId)
                ->select('seats.SeatRow', 'seats.SeatNo')
                ->get();

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
                'venue' => 'สถานที่จัดงาน (ระบบอัตโนมัติ)',
                'zone' => $bookingInfo->zoneName,
                'tickets' => $ticketList
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'DB Error: ' . $e->getMessage()
            ], 500);
        }
    }

    // =================================================================
    // 6. แจ้งเตือนการชำระเงินสำเร็จ และเปลี่ยนสถานะที่นั่ง + บันทึกบิลลง Payments
    // =================================================================
    public function paymentSuccess(Request $request, $bookingId) {
        // รับค่ายอดเงินที่ส่งมาจากหน้า QR Code (ถ้าไม่ได้ส่งมาให้เป็น 0)
        $amount = $request->input('amount', 0); 

        return DB::transaction(function () use ($bookingId, $amount) {
            
            // 1. อัปเดตสถานะบิลหลักให้เป็น 'ชำระเงินแล้ว'
            DB::table('bookings')
                ->where('Booking_id', $bookingId)
                ->update(['BKStatus' => 'ชำระเงินแล้ว']);

            // ⭐️ 2. บันทึกข้อมูลลงตาราง payments
            DB::table('payments')->insert([
                'Booking_id' => $bookingId,
                'PMDate'     => now(), // ใช้วันเวลาปัจจุบัน
                'Amount'     => $amount,
                'PMStatus'   => 'ชำระเรียบร้อยแล้ว', 
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // 3. หา Seat_id ทั้งหมดในบิลนี้
            $seatIds = DB::table('booking_details')
                        ->where('Booking_id', $bookingId)
                        ->pluck('Seat_id');
            
            // 4. เปลี่ยนสถานะเก้าอี้ให้เป็น 'จองแล้ว'
            DB::table('seats')
                ->whereIn('Seat_id', $seatIds)
                ->update(['SeatStatus' => 'จองแล้ว']); 

            // 5. อัปเดตสถานะ E-Ticket ให้ใช้งานได้
            DB::table('booking_details')
                ->where('Booking_id', $bookingId)
                ->update(['ETStatus' => 'ใช้งานได้']);

            return response()->json(['message' => 'Payment recorded successfully']);
        });
    }
    // =================================================================
    // 7. ดูรายละเอียดคำสั่งซื้อรายตัว (Order Detail) ⭐️ (ส่วนที่แก้ไข)
    // =================================================================
    public function getOrderDetails($orderId)
    {
        try {
            // ⭐️ 1. ดึงข้อมูลหลัก (เปลี่ยนเป็น leftJoin ทั้งหมด เพื่อป้องกันการเตะข้อมูลทิ้ง)
            $orderInfo = DB::table('bookings')
                ->where('bookings.Booking_id', $orderId)
                ->leftJoin('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                ->leftJoin('events', 'event_date_times.Event_id', '=', 'events.Event_id')
                ->leftJoin('ticket_zones', 'bookings.Zone_id', '=', 'ticket_zones.Zone_id')
                // เอา leftJoin payments ออกไปเลย เพราะไม่ได้ใช้งานใน Response ช่วยลดโอกาส Error
                ->select(
                    'bookings.Booking_id',
                    'events.eventName',
                    'event_date_times.startDT',
                    'event_date_times.endDT',
                    'ticket_zones.zoneName',
                    'bookings.quantity',
                    'bookings.totalPrice',
                    'bookings.BKStatus',
                    'bookings.created_at'
                )->first();

            if (!$orderInfo) {
                return response()->json(['message' => 'ไม่พบข้อมูลคำสั่งซื้อ'], 404);
            }

            // ⭐️ 2. ดึงที่นั่ง
            $seatRecords = DB::table('booking_details')
                ->join('seats', 'booking_details.Seat_id', '=', 'seats.Seat_id')
                ->where('booking_details.Booking_id', $orderId)
                ->select('seats.SeatRow', 'seats.SeatNo')
                ->get();
            
            $seats = $seatRecords->map(function ($seat) {
                return $seat->SeatRow . $seat->SeatNo;
            })->toArray();

            // ⭐️ 3. คำนวณราคา (ดักค่า Null ไว้เสมอ ป้องกัน Error Type Mismatch)
            $quantity = $orderInfo->quantity ?? 0;
            $totalPrice = $orderInfo->totalPrice ?? 0;
            $fee = $quantity * 20;
            $subtotal = $totalPrice - $fee;

            // ⭐️ 4. จัดการวันที่ (ดักเช็ค Null เสมอก่อนส่งเข้าฟังก์ชัน Carbon)
            $eventDate = $orderInfo->startDT ? \Carbon\Carbon::parse($orderInfo->startDT)->translatedFormat('l, d M Y') : 'ไม่ระบุวันที่';
            $startTime = $orderInfo->startDT ? \Carbon\Carbon::parse($orderInfo->startDT)->format('H:i') : '';
            $endTime = $orderInfo->endDT ? \Carbon\Carbon::parse($orderInfo->endDT)->format('H:i') : '';
            
            $eventTime = $startTime;
            if ($endTime) $eventTime .= ' - ' . $endTime;

            $transactionDate = $orderInfo->created_at ? \Carbon\Carbon::parse($orderInfo->created_at)->format('d/m/Y H:i:s') : '-';

            // ⭐️ 5. แมปข้อมูลเตรียมส่งกลับให้ React
            $response = [
                'id' => $orderInfo->Booking_id,
                'order_number' => 'ORD-' . str_pad($orderInfo->Booking_id, 4, '0', STR_PAD_LEFT),
                'booking_id' => $orderInfo->Booking_id,
                'status' => ($orderInfo->BKStatus === 'ชำระเงินแล้ว') ? 'success' : (($orderInfo->BKStatus === 'ยกเลิก') ? 'failed' : 'pending'),
                'event_name' => $orderInfo->eventName ?? 'ไม่ระบุอีเวนต์',
                'ticket_count' => $quantity,
                'event_date' => $eventDate,
                'event_time' => $eventTime,
                'venue' => 'สถานที่จัดงาน (ระบบอัตโนมัติ)',
                'zone' => $orderInfo->zoneName ?? 'ไม่ระบุโซน',
                'seats' => !empty($seats) ? implode(', ', $seats) : 'ไม่ระบุที่นั่ง',
                'subtotal' => $subtotal,
                'fee' => $fee,
                'total' => $totalPrice,
                'payment_method' => 'Thai QR Payment',
                'transaction_date' => $transactionDate,
                // ✅ เพิ่มบรรทัดนี้เข้าไป เพื่อส่งเวลา Timestamp (วินาที) ไปให้ React คำนวณ
                'created_at_timestamp' => $orderInfo->created_at ? \Carbon\Carbon::parse($orderInfo->created_at)->timestamp : 0,
            ];

            return response()->json($response, 200);

        } catch (\Exception $e) {
            // เพิ่มการ Return ข้อความ Error กลับไปให้ Front-end จะได้รู้ว่าบั๊กบรรทัดไหน
            return response()->json([
                'message' => 'Backend Error: ' . $e->getMessage(),
                'line' => $e->getLine()
            ], 500);
        }
    }
}