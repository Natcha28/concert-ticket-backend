<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TicketZone;
use App\Models\Seat;
use Illuminate\Support\Facades\DB;

class TicketZoneController extends Controller
{
    public function store(Request $request)
    {
        / 1. รับข้อมูลจากหน้าบ้าน
        $validated = $request->validate([
            'Event_id'     => 'required|exists:events,Event_id',
            'HallZone_id'  => 'required|exists:hall_zones,HallZone_id',
            'zoneName'     => 'required|string|max:30',
            'colorZone'    => 'required|string|max:20',
            'priceperTick' => 'required|integer',
            // ให้รับค่าแบบ nullable เผื่อหน้าบ้านไม่ได้ส่งมาสำหรับโซนแบบ Fixed
            'rows_count'   => 'nullable|integer|min:1',
            'seats_per_row'=> 'nullable|integer|min:1',
        ]);

        try {
            return DB::transaction(function () use ($validated) {
                $zoneName = strtoupper(trim($validated['zoneName']));
                
                // 2. เช็คว่าเป็นโซนของเมืองไทยรัชดาลัยหรือไม่
                $fixedSeats = $this->getRachadalaiFixedSeats($zoneName);
                
                // 3. คำนวณจำนวนที่นั่งรวม (ถ้าเป็นผัง Fixed ให้นับจาก Array เลย)
                if ($fixedSeats) {
                    $totalSeat = count($fixedSeats);
                } else {
                    $totalSeat = ($validated['rows_count'] ?? 0) * ($validated['seats_per_row'] ?? 0);
                }

                // 4. บันทึกข้อมูลโซนลงตาราง ticket_zones
                $ticketZone = TicketZone::create([
                    'Event_id'     => $validated['Event_id'],
                    'HallZone_id'  => $validated['HallZone_id'],
                    'zoneName'     => $validated['zoneName'],
                    'colorZone'    => $validated['colorZone'],
                    'priceperTick' => $validated['priceperTick'],
                    'totalSeat'    => $totalSeat,
                    'remainSeat'   => $totalSeat,
                ]);

                // 5. เตรียมข้อมูลเก้าอี้เพื่อบันทึกลงตาราง seats
                $seatDataToInsert = [];
                $now = now();

                if ($fixedSeats) {
                    // 🎯 กรณีเป็นผังรัชดาลัย: สร้างตามรอยแหว่งและเลขที่นั่งจริงเป๊ะๆ
                    foreach ($fixedSeats as $seat) {
                        $seatDataToInsert[] = [
                            'Zone_id'    => $ticketZone->Zone_id,
                            'SeatRow'    => $seat['row'],
                            'SeatNo'     => $seat['num'],
                            'SeatStatus' => 'ว่าง',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                } else {
                    // 🎯 กรณีผังทั่วไป: สร้างแบบสี่เหลี่ยมปกติตามจำนวนที่กรอกมา
                    $rowsCount = $validated['rows_count'] ?? 1;
                    $seatsPerRow = $validated['seats_per_row'] ?? 1;
                    $rows = range('A', 'Z');
                    
                    for ($i = 0; $i < $rowsCount; $i++) {
                        $rowLetter = $rows[$i] ?? 'A';
                        for ($j = 1; $j <= $seatsPerRow; $j++) {
                            $seatDataToInsert[] = [
                                'Zone_id'    => $ticketZone->Zone_id,
                                'SeatRow'    => $rowLetter,
                                'SeatNo'     => str_pad($j, 2, '0', STR_PAD_LEFT),
                                'SeatStatus' => 'ว่าง',
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }
                }

                // 6. ใช้ insert() เพื่อบันทึกข้อมูลรวดเดียว (Bulk Insert) 
                // เพิ่ม array_chunk ป้องกัน Database รับข้อมูลก้อนใหญ่เกินไป
                foreach (array_chunk($seatDataToInsert, 500) as $chunk) {
                    Seat::insert($chunk);
                }

                return response()->json([
                    'message' => 'สร้างโซน ' . $ticketZone->zoneName . ' พร้อมที่นั่ง ' . $totalSeat . ' ที่สำเร็จ!',
                    'data'    => $ticketZone
                ], 201);
            });

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ฟังก์ชันแปลงผังเมืองไทยรัชดาลัย ให้เป็นข้อมูลที่นั่งแบบเป๊ะๆ 100%
     */
    private function getRachadalaiFixedSeats($zoneName)
    {
        $layout = [];

        switch ($zoneName) {
            // ================= LEVEL 1 =================
            case 'L1':
                $layout = [
                    'A' => [5, 12], 'B' => [5, 12], 'C' => [4, 12], 'D' => [3, 12],
                    'E' => [2, 12], 'F' => [2, 12], 'G' => [2, 12], 'H' => [2, 12]
                ]; break;
            case 'C1':
                $layout = [
                    'A' => [13, 28], 'B' => [13, 28], 'C' => [13, 28], 'D' => [13, 28],
                    'E' => [13, 28], 'F' => [13, 28], 'G' => [13, 28], 'H' => [13, 28]
                ]; break;
            case 'R1':
                $layout = [
                    'A' => [29, 36], 'B' => [29, 37], 'C' => [29, 37], 'D' => [29, 38],
                    'E' => [29, 39], 'F' => [29, 39], 'G' => [29, 39], 'H' => [29, 39]
                ]; break;

            // ================= LEVEL 2 =================
            case 'L2':
                foreach (range('I', 'P') as $r) $layout[$r] = [1, 12]; break;
            case 'C2':
                foreach (range('I', 'P') as $r) $layout[$r] = [13, 28]; break;
            case 'R2':
                foreach (range('I', 'P') as $r) $layout[$r] = [29, 40]; break;

            // ================= LEVEL 3 =================
            case 'L3':
                foreach (range('Q', 'T') as $r) $layout[$r] = [1, 12]; break;
            case 'C3':
                foreach (range('Q', 'T') as $r) $layout[$r] = [13, 28]; break;
            case 'R3':
                foreach (range('Q', 'T') as $r) $layout[$r] = [29, 40]; break;

            // ================= LEVEL 4 =================
            case 'L4':
                $layout = ['U'=>[1,12], 'V'=>[1,12], 'W'=>[1,12], 'X'=>[1,11], 'Y'=>[1,11], 'Z'=>[1,11]]; break;
            case 'C4':
                $layout = ['U'=>[13,28], 'V'=>[13,28], 'W'=>[13,28]]; break; // โซน C4 มีแค่ 3 แถว U, V, W
            case 'R4':
                $layout = ['U'=>[29,40], 'V'=>[30,40], 'W'=>[30,40], 'X'=>[30,40], 'Y'=>[30,40], 'Z'=>[30,40]]; break;

            // ================= LEVEL 5 =================
            case 'L5':
                foreach (['AA','BB','CC','DD'] as $r) $layout[$r] = [2, 12]; break;
            case 'C5':
                foreach (['AA','BB','CC','DD'] as $r) $layout[$r] = [13, 28]; break;
            case 'R5':
                $layout = ['AA'=>[29,39], 'BB'=>[29,40], 'CC'=>[29,39], 'DD'=>[29,40]]; break;

            // ================= LEVEL 6 =================
            case 'L6':
                foreach (['EE','FF','GG','HH','II','JJ','KK'] as $r) $layout[$r] = [2, 12]; break;
            case 'C6':
                foreach (['EE','FF','GG','HH','II','JJ','KK'] as $r) $layout[$r] = [13, 28]; break;
            case 'R6':
                $layout = [
                    'EE'=>[29,40], 'FF'=>[29,39], 'GG'=>[29,40], 'HH'=>[29,39], 
                    'II'=>[29,40], 'JJ'=>[29,39], 'KK'=>[29,39]
                ]; break;

            default:
                return null; // ถ้าเป็นโซนอื่นที่ไม่อยู่ในผัง ให้คืนค่ากลับไปสร้างแบบปกติ
        }

        $seats = [];
        // สร้าง Array เก้าอี้ออกมาเป็นเบอร์ 01, 02... ให้ตรงเป๊ะ
        foreach ($layout as $row => $range) {
            for ($i = $range[0]; $i <= $range[1]; $i++) {
                $seats[] = [
                    'row' => $row,
                    'num' => str_pad($i, 2, '0', STR_PAD_LEFT)
                ];
            }
        }

        return $seats;
    } 
}