<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * 1. หน้าตารางรวม: รายการตั๋วทั้งหมด (ตั๋วของฉัน)
     */
    public function index(Request $request)
    {
        $userId = 3; // ข้อมูลคุณ Vee
        try {
            $rawOrders = DB::select('
                SELECT 
                    bookings."Booking_id", 
                    events."eventName", 
                    events."bannerImage", /* 🚨 ใช้ชื่อคอลัมน์จริงจาก DB */
                    bookings."totalPrice", 
                    bookings."BKStatus",
                    bookings.created_at
                FROM bookings
                JOIN event_date_times ON bookings."Datetime_id" = event_date_times."Datetime_id"
                JOIN events ON event_date_times."Event_id" = events."Event_id"
                WHERE bookings."Mem_id" = ?
                ORDER BY bookings.created_at DESC
            ', [$userId]);

            $formattedOrders = array_map(function($order) {
                return [
                    'id' => $order->Booking_id,
                    'order_number' => 'ORD-2026-' . str_pad($order->Booking_id, 3, '0', STR_PAD_LEFT),
                    'event_name' => $order->eventName, 
                    'image' => $order->bannerImage, /* ดึง URL Cloudinary มาใช้ตรงๆ */
                    'date' => date('d M Y', strtotime($order->created_at)),
                    'amount' => number_format($order->totalPrice),
                    'status' => ($order->BKStatus === "ชำระเงินแล้ว") ? "success" : "failed"
                ];
            }, $rawOrders);

            return response()->json($formattedOrders);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * 2. หน้ารายละเอียดออเดอร์
     */
    public function show(Request $request, $id)
    {
        $userId = 3; 
        try {
            $rawOrder = DB::selectOne('
                SELECT 
                    bookings."Booking_id", 
                    events."eventName", 
                    events."bannerImage", /* */
                    bookings."totalPrice", 
                    bookings."quantity",
                    bookings."Zone_id", 
                    bookings."BKStatus", 
                    bookings."BKDate", 
                    bookings.created_at,
                    STRING_AGG(CONCAT(seats."SeatRow", seats."SeatNo"), \', \') AS seats_list
                FROM bookings
                JOIN event_date_times ON bookings."Datetime_id" = event_date_times."Datetime_id"
                JOIN events ON event_date_times."Event_id" = events."Event_id"
                LEFT JOIN booking_details ON bookings."Booking_id" = booking_details."Booking_id"
                LEFT JOIN seats ON booking_details."Seat_id" = seats."Seat_id"
                WHERE bookings."Mem_id" = ? AND bookings."Booking_id" = ?
                GROUP BY 
                    bookings."Booking_id", events."eventName", events."bannerImage", 
                    bookings."totalPrice", bookings."quantity", bookings."Zone_id", 
                    bookings."BKStatus", bookings."BKDate", bookings.created_at
            ', [$userId, $id]);

            if (!$rawOrder) return response()->json(['message' => 'Order not found'], 404);

            return response()->json([
                'id' => $rawOrder->Booking_id,
                'order_number' => 'ORD-2026-' . str_pad($rawOrder->Booking_id, 3, '0', STR_PAD_LEFT),
                'event_name' => $rawOrder->eventName,
                'image' => $rawOrder->bannerImage,
                'event_date' => date('d M Y', strtotime($rawOrder->BKDate)),
                'event_time' => date('H:i น.', strtotime($rawOrder->BKDate)),
                'venue' => 'Impact Arena, Bangkok', 
                'zone' => $rawOrder->Zone_id,
                'seats' => $rawOrder->seats_list ?? 'ไม่ระบุที่นั่ง',
                'ticket_count' => $rawOrder->quantity,
                'status' => ($rawOrder->BKStatus === "ชำระเงินแล้ว") ? "success" : "failed",
                'payment_method' => 'ระบบ BEATPASS',
                'transaction_date' => date('d M Y, H:i น.', strtotime($rawOrder->created_at)),
                'subtotal' => $rawOrder->totalPrice,
                'total' => $rawOrder->totalPrice,
                'booking_id' => $rawOrder->Booking_id 
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}