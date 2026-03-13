<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeatController extends Controller
{
    public function getSeats(Request $request)
    {
        try {
            $eventId = $request->query('eventId');
            $zoneName = $request->query('zoneName');

            if (!$eventId || !$zoneName) {
                return response()->json(['error' => 'Missing parameters'], 400);
            }

            // 1. ดึงข้อมูลที่นั่งและประวัติบิล โดย "เรียงลำดับเวลาบิลจากใหม่ไปเก่า (desc)"
            $seats = DB::table('seats')
                ->join('ticket_zones', 'seats.Zone_id', '=', 'ticket_zones.Zone_id')
                ->leftJoin('booking_details', 'seats.Seat_id', '=', 'booking_details.Seat_id')
                ->leftJoin('bookings', 'booking_details.Booking_id', '=', 'bookings.Booking_id')
                ->where('ticket_zones.Event_id', $eventId)
                ->where('ticket_zones.zoneName', $zoneName)
                ->select(
                    'seats.Seat_id',
                    'seats.Zone_id',
                    'seats.SeatRow',
                    'seats.SeatNo',
                    'seats.SeatStatus as original_status',
                    'bookings.BKStatus',
                    'bookings.created_at' // 👈 ดึงเวลาสร้างบิลมาด้วย
                )
                // ⭐️ สำคัญมาก: เรียงบิลที่สร้างล่าสุดขึ้นมาก่อน เพื่อเช็คสถานะปัจจุบัน
                ->orderBy('bookings.created_at', 'desc') 
                ->get();

            // 2. จัดกลุ่มตามเลขที่นั่ง
            $groupedSeats = $seats->groupBy(function ($item) {
                return $item->SeatRow . str_pad($item->SeatNo, 2, '0', STR_PAD_LEFT);
            });

            $formattedSeats = [];

            // 3. วนลูปเช็คสถานะ
            foreach ($groupedSeats as $seatKey => $group) {
                // ⭐️ หยิบเอา "ข้อมูลแถวแรก" ซึ่งจะเป็น "บิลใบล่าสุด" มาเช็คเท่านั้น
                $latestRecord = $group->first(); 
                
                $finalStatus = 'ว่าง';

                // ถ้าบิล "ใบล่าสุด" ยังรอจ่าย หรือจ่ายแล้ว แปลว่าโดนจองแล้ว!
                if ($latestRecord->BKStatus === 'รอการชำระเงิน' || $latestRecord->BKStatus === 'ชำระเงินแล้ว') {
                    $finalStatus = 'ขายแล้ว';
                } 
                // แต่ถ้าไม่มีบิล หรือบิลล่าสุดคือ 'ยกเลิก' ให้ยึดตามคอลัมน์ SeatStatus ของตารางเก้าอี้
                else if ($latestRecord->original_status !== 'ว่าง') {
                    $finalStatus = 'ขายแล้ว';
                }

                $formattedSeats[] = [
                    'Seat_id'    => $latestRecord->Seat_id,
                    'Zone_id'    => $latestRecord->Zone_id,
                    'SeatRow'    => $latestRecord->SeatRow,
                    'SeatNo'     => str_pad($latestRecord->SeatNo, 2, '0', STR_PAD_LEFT),
                    'SeatStatus' => $finalStatus
                ];
            }

            return response()->json($formattedSeats);

        } catch (\Exception $e) {
            return response()->json([
                'error' => true,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}