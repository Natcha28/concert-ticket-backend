<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TicketZone;
use App\Models\Seat; // ✅ เรียกใช้ Model Seat
use Illuminate\Support\Facades\DB;

class TicketZoneController extends Controller
{
    public function store(Request $request)
    {
        // 1. รับข้อมูลและตรวจสอบความถูกต้อง (อ้างอิงชื่อฟิลด์จาก Migration ของคุณ)
        $validated = $request->validate([
            'Event_id'     => 'required|exists:events,Event_id',
            'HallZone_id'  => 'required|exists:hall_zones,HallZone_id',
            'zoneName'     => 'required|string|max:30',
            'colorZone'    => 'required|string|max:20',
            'priceperTick' => 'required|integer',
            'rows_count'   => 'required|integer|min:1',    // รับจำนวนแถวจากหน้าบ้าน (เช่น 5 แถว)
            'seats_per_row'=> 'required|integer|min:1',    // รับที่นั่งต่อแถว (เช่น 10 ที่)
            // 'Datetime_id'  => 'required|exists:event_date_times,Datetime_id', // เปิดใช้หากต้องการผูกที่นั่งกับรอบ
        ]);

        try {
            return DB::transaction(function () use ($validated, $request) {
                
                // คำนวณจำนวนที่นั่งรวม
                $totalSeat = $validated['rows_count'] * $validated['seats_per_row'];

                // 2. บันทึกข้อมูลลงตาราง ticket_zones (ตามโครงสร้าง Migration ของคุณ)
                $ticketZone = TicketZone::create([
                    'Event_id'     => $validated['Event_id'],
                    'HallZone_id'  => $validated['HallZone_id'],
                    'zoneName'     => $validated['zoneName'],
                    'colorZone'    => $validated['colorZone'],
                    'priceperTick' => $validated['priceperTick'],
                    'totalSeat'    => $totalSeat,
                    'remainSeat'   => $totalSeat, // เริ่มต้นที่นั่งว่างเท่ากับทั้งหมด
                ]);

                // 3. วนลูปเพื่อสร้างที่นั่งลงตาราง seats อัตโนมัติ
                $rows = range('A', 'Z'); // เตรียมแถว A, B, C...
                
                for ($i = 0; $i < $validated['rows_count']; $i++) {
                    $rowLetter = $rows[$i]; 

                    for ($j = 1; $j <= $validated['seats_per_row']; $j++) {
                        Seat::create([
                            'Zone_id'    => $ticketZone->Zone_id, // ใช้ Zone_id ที่เพิ่งสร้าง
                            'SeatRow'    => $rowLetter,           // ตรงตาม Migration: string(5)
                            'SeatNo'     => str_pad($j, 2, '0', STR_PAD_LEFT), // ตรงตาม Migration: string(6) (เช่น 01, 02)
                            'SeatStatus' => 'ว่าง',               // ตรงตาม Migration: enum('ว่าง', ...)
                            // 'Datetime_id' => $validated['Datetime_id'], // ใส่เพิ่มหากในตาราง seats มีฟิลด์นี้
                        ]);
                    }
                }

                return response()->json([
                    'message' => 'เพิ่มโซน ' . $ticketZone->zoneName . ' และสร้างที่นั่งเรียบร้อย!',
                    'data'    => $ticketZone
                ], 201);
            });

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
            ], 500);
        }
    }
}