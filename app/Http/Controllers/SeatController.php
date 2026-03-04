<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeatController extends Controller
{
    public function getSeats(Request $request)
    {
        try {
            // 1. รับค่าจาก URL (รองรับทั้งแบบ zone_id ตรงๆ หรือ eventId + zoneName)
            $zoneId = $request->query('zone_id');
            $eventId = $request->query('eventId');
            $zoneName = $request->query('zoneName');

            // 2. เริ่มสร้าง Query สำหรับดึงตาราง seats
            $query = DB::table('seats');

            if ($zoneId) {
                // กรณีที่ 1: หน้า React ส่ง zone_id มาตรงๆ (เช่น ?zone_id=164)
                $query->where('Zone_id', $zoneId);
            } elseif ($eventId && $zoneName) {
                // กรณีที่ 2: หน้า React ส่ง eventId และ zoneName มา (เช่น ?eventId=44&zoneName=L1)
                $query->join('ticket_zones', 'seats.Zone_id', '=', 'ticket_zones.Zone_id') 
                      ->where('ticket_zones.Event_id', $eventId)
                      ->where('ticket_zones.zoneName', $zoneName)
                      ->select('seats.*'); // เอาแค่ข้อมูลที่นั่ง ไม่เอาข้อมูลโซนมาปน
            } else {
                // ถ้าไม่ส่งอะไรมาเลยให้เตือนกลับไป
                return response()->json(['error' => 'Missing parameters (zone_id OR eventId+zoneName required)'], 400);
            }

            // 3. ดึงข้อมูลจากฐานข้อมูล
            $seats = $query->get();

            // 4. ส่งกลับไปให้ React แบบเพียวๆ เลย ไม่ต้องแปลงร่าง
            // เพื่อให้หน้า React ของคุณสามารถเรียกใช้ dbSeat.Seat_id, dbSeat.SeatRow ได้ตรงเป๊ะ!
            return response()->json($seats);

        } catch (\Exception $e) {
            // ดักจับ Error เผื่อฐานข้อมูลมีปัญหา
            return response()->json([
                'error' => true,
                'message' => 'Database Error: ' . $e->getMessage()
            ], 500);
        }
    }
}