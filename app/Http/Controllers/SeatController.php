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
            $zoneName = strtoupper(trim($request->query('zoneName')));
            $roundId = $request->query('roundId'); // 🌟 สำคัญมาก! ต้องรับค่า roundId จาก React มาด้วย

            if (!$eventId || !$zoneName) {
                return response()->json(['error' => 'Missing parameters'], 400);
            }

            // 🌟 วิธีแก้ปัญหาชื่อโซน: สร้างตัวแปรดักไว้ทั้งแบบมีวรรคและไม่มีวรรค (ZONE 2 vs ZONE2)
            $zoneNameNoSpace = str_replace(' ', '', $zoneName);
            $zoneNameWithSpace = str_replace('ZONE', 'ZONE ', $zoneNameNoSpace);

            // 1. ดึงข้อมูลที่นั่งและประวัติบิล
            $seats = DB::table('seats')
                ->join('ticket_zones', 'seats.Zone_id', '=', 'ticket_zones.Zone_id')
                ->leftJoin('booking_details', 'seats.Seat_id', '=', 'booking_details.Seat_id')
                ->leftJoin('bookings', function($join) use ($roundId) {
                    $join->on('booking_details.Booking_id', '=', 'bookings.Booking_id');
                    // 🌟 ดักบักข้ามรอบ: ดึงบิลจอง "เฉพาะของรอบการแสดงนี้ (Datetime_id)" เท่านั้น!
                    if ($roundId) {
                        $join->where('bookings.Datetime_id', '=', $roundId);
                    }
                })
                ->where('ticket_zones.Event_id', $eventId)
                ->where(function($query) use ($zoneName, $zoneNameNoSpace, $zoneNameWithSpace) {
                    // ค้นหาเผื่อไว้ทุกรูปแบบ ป้องกันข้อมูลไม่ตรงกัน
                    $query->where('ticket_zones.zoneName', $zoneName)
                          ->orWhere('ticket_zones.zoneName', $zoneNameNoSpace)
                          ->orWhere('ticket_zones.zoneName', $zoneNameWithSpace);
                })
                ->select(
                    'seats.Seat_id',
                    'seats.Zone_id',
                    'seats.SeatRow',
                    'seats.SeatNo',
                    'seats.SeatStatus as original_status',
                    'bookings.BKStatus',
                    'bookings.created_at'
                )
                ->orderBy('bookings.created_at', 'desc')
                ->get();

            // 2. จัดกลุ่มตามเลขที่นั่ง
            $groupedSeats = $seats->groupBy(function ($item) {
                return $item->SeatRow . str_pad($item->SeatNo, 2, '0', STR_PAD_LEFT);
            });

            $formattedSeats = [];

            // 3. วนลูปเช็คสถานะ
            foreach ($groupedSeats as $seatKey => $group) {
                // หยิบเอา "ข้อมูลแถวแรก" ซึ่งจะเป็น "บิลใบล่าสุดของรอบนี้" มาเช็คเท่านั้น
                $latestRecord = $group->first(); 
                
                $finalStatus = 'ว่าง';

                // ถ้าบิลใบล่าสุด ยังรอจ่าย หรือจ่ายแล้ว แปลว่าโดนจองแล้ว!
                if ($latestRecord->BKStatus === 'รอการชำระเงิน' || $latestRecord->BKStatus === 'ชำระเงินแล้ว') {
                    $finalStatus = 'ขายแล้ว';
                } 
                // แต่ถ้าไม่มีบิล ให้ยึดตามคอลัมน์ SeatStatus ของตารางเก้าอี้
                //else if ($latestRecord->original_status !== 'ว่าง') {
                  //  $finalStatus = 'ขายแล้ว';
                //}

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