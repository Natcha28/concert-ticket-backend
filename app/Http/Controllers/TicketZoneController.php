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
        // 1. รับข้อมูลจากหน้าบ้าน
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
                
                // 2. เช็คว่าเป็นโซนแบบ Fixed ของรัชดาลัย, อิมแพ็ค หรือ ม.รังสิต
                $fixedSeats = $this->getRachadalaiFixedSeats($zoneName) ?? $this->getDynamicLayoutSeats($zoneName);
                
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
                    // 🎯 กรณีเป็นผัง Fixed: สร้างตามรอยแหว่งและเลขที่นั่งจริงเป๊ะๆ
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
     * ฟังก์ชันสร้างผังเก้าอี้อัจฉริยะสำหรับ อิมแพค อารีน่า และ ม.รังสิต
     * สร้างจาก Logic หน้า React แบบ 1:1
     */
    private function getDynamicLayoutSeats($zoneName)
    {
        $name = strtoupper(trim($zoneName));
        $maxCol = 20;
        $startNum = 1;
        $matrix = [];
        $rowLetters = range('A', 'Z');
        $isFixedLayout = false;

        // ============================================
        // 🎯 อิมแพค อารีน่า (IMPACT ARENA)
        // ============================================
        if (in_array($name, ["A1", "A2", "A3", "A4"])) {
            $isFixedLayout = true;
            $rowLetters = range('A', 'T');
            $maxCol = 25;
            $matrix = [['l' => 0, 'r' => 0]];
        }
        else if ($name === "A5") {
            $isFixedLayout = true;
            $rowLetters = range('A', 'T');
            $maxCol = 25;
            for($i=0; $i<15; $i++) $matrix[] = ['l'=>0, 'r'=>0];
            array_push($matrix, ['l'=>2,'r'=>0], ['l'=>4,'r'=>0], ['l'=>6,'r'=>0], ['l'=>8,'r'=>0], ['l'=>10,'r'=>0]);
        }
        else if ($name === "A6") {
            $isFixedLayout = true;
            $rowLetters = range('A', 'T');
            $maxCol = 25;
            for($i=0; $i<15; $i++) $matrix[] = ['l'=>0, 'r'=>0];
            array_push($matrix, ['l'=>0,'r'=>2], ['l'=>0,'r'=>4], ['l'=>0,'r'=>6], ['l'=>0,'r'=>8], ['l'=>0,'r'=>10]);
        }
        else if ($name === "A7") {
            $isFixedLayout = true;
            $rowLetters = range('A', 'J');
            $maxCol = 48;
            for($i=0; $i<5; $i++) $matrix[] = ['l'=>0, 'r'=>0];
            array_push($matrix, ['l'=>2,'r'=>2], ['l'=>4,'r'=>4], ['l'=>6,'r'=>6], ['l'=>8,'r'=>8], ['l'=>10,'r'=>10]);
        }
        else if (in_array($name, ["SB", "SC", "SD", "SL", "SM", "SN"])) {
            $isFixedLayout = true;
            $rowLetters = range('A', 'H');
            $maxCol = 20;
            $matrix = [['l'=>0, 'r'=>0]];
        }
        else if (in_array($name, ["SE", "SK"])) {
            $isFixedLayout = true;
            $rowLetters = range('A', 'H');
            $maxCol = 27;
            $matrix = [['l'=>0,'r'=>6], ['l'=>0,'r'=>5], ['l'=>0,'r'=>4], ['l'=>0,'r'=>4], ['l'=>0,'r'=>3], ['l'=>0,'r'=>2], ['l'=>0,'r'=>1], ['l'=>0,'r'=>0]];
        }
        else if (in_array($name, ["SF", "SJ"])) {
            $isFixedLayout = true;
            $rowLetters = range('A', 'H');
            $maxCol = 24;
            $matrix = [['l'=>0,'r'=>8], ['l'=>0,'r'=>7], ['l'=>0,'r'=>6], ['l'=>0,'r'=>5], ['l'=>0,'r'=>3], ['l'=>0,'r'=>2], ['l'=>0,'r'=>1], ['l'=>0,'r'=>0]];
        }
        else if (in_array($name, ["SG", "SI"])) {
            $isFixedLayout = true;
            $rowLetters = range('A', 'H');
            $maxCol = 20;
            $matrix = [['l'=>0,'r'=>6], ['l'=>0,'r'=>5], ['l'=>0,'r'=>4], ['l'=>0,'r'=>3], ['l'=>0,'r'=>3], ['l'=>0,'r'=>2], ['l'=>0,'r'=>1], ['l'=>0,'r'=>0]];
        }
        else if ($name === "SH") {
            $isFixedLayout = true;
            $rowLetters = range('A', 'H');
            $maxCol = 19;
            $matrix = [['l'=>0, 'r'=>0]];
        }
        else if (in_array($name, ["B", "T"])) {
            $isFixedLayout = true;
            $rowLetters = range('A', 'R');
            $maxCol = 10;
            $isT = ($name === "T");
            $matrix = [
                ['l' => $isT ? 0 : 5, 'r' => $isT ? 5 : 0], ['l' => $isT ? 0 : 3, 'r' => $isT ? 3 : 0], ['l' => $isT ? 0 : 3, 'r' => $isT ? 3 : 0], ['l' => $isT ? 0 : 3, 'r' => $isT ? 3 : 0],
                ['l' => $isT ? 0 : 3, 'r' => $isT ? 3 : 0], ['l' => $isT ? 0 : 3, 'r' => $isT ? 3 : 0], ['l' => $isT ? 0 : 4, 'r' => $isT ? 4 : 0], ['l' => $isT ? 0 : 5, 'r' => $isT ? 5 : 0],
                ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0]
            ];
        }
        else if (in_array($name, ["C", "D", "S", "R"])) {
            $isFixedLayout = true;
            $rowLetters = ["AA", "A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q"];
            $maxCol = 20;
            $matrix = [
                ['l'=>4,'r'=>5], ['l'=>3,'r'=>4], ['l'=>3,'r'=>4], ['l'=>3,'r'=>4], ['l'=>3,'r'=>4], ['l'=>3,'r'=>4], ['l'=>4,'r'=>5], ['l'=>5,'r'=>5],
                ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0]
            ];
        }
        else if (in_array($name, ["E", "Q"])) {
            $isFixedLayout = true;
            $rowLetters = ["AA", "A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q"];
            $maxCol = 23;
            $matrix = [
                ['l'=>5,'r'=>9], ['l'=>4,'r'=>7], ['l'=>4,'r'=>6], ['l'=>4,'r'=>6], ['l'=>4,'r'=>6], ['l'=>4,'r'=>5], ['l'=>5,'r'=>4], ['l'=>5,'r'=>5],
                ['l'=>0,'r'=>5], ['l'=>0,'r'=>5], ['l'=>0,'r'=>5], ['l'=>0,'r'=>4], ['l'=>0,'r'=>3], ['l'=>0,'r'=>2], ['l'=>0,'r'=>1], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0]
            ];
        }
        else if (in_array($name, ["F", "P"])) {
            $isFixedLayout = true;
            $rowLetters = range('A', 'U');
            $maxCol = 19;
            $isP = ($name === "P");
            $matrix = [
                ['l' => $isP ? 3 : 12, 'r' => $isP ? 12 : 3], ['l' => $isP ? 3 : 11, 'r' => $isP ? 11 : 3], ['l' => $isP ? 3 : 11, 'r' => $isP ? 11 : 3], ['l' => $isP ? 3 : 10, 'r' => $isP ? 10 : 3],
                ['l' => $isP ? 3 : 10, 'r' => $isP ? 10 : 3], ['l' => $isP ? 3 : 9,  'r' => $isP ? 9 : 3],  ['l' => $isP ? 3 : 9,  'r' => $isP ? 9 : 3],  ['l' => $isP ? 0 : 5,  'r' => $isP ? 5 : 0],
                ['l' => $isP ? 0 : 5,  'r' => $isP ? 5 : 0],  ['l' => $isP ? 0 : 4,  'r' => $isP ? 4 : 0],  ['l' => $isP ? 0 : 4,  'r' => $isP ? 4 : 0],  ['l' => $isP ? 0 : 4,  'r' => $isP ? 4 : 0],
                ['l' => $isP ? 0 : 3,  'r' => $isP ? 3 : 0],  ['l' => $isP ? 0 : 2,  'r' => $isP ? 2 : 0],  ['l' => $isP ? 0 : 1,  'r' => $isP ? 1 : 0],  ['l' => $isP ? 0 : 1,  'r' => $isP ? 1 : 0],
                ['l' => 0, 'r' => 0], ['l' => $isP ? 0 : 1,  'r' => $isP ? 1 : 0], ['l' => $isP ? 0 : 2,  'r' => $isP ? 2 : 0], ['l' => $isP ? 0 : 3,  'r' => $isP ? 3 : 0], ['l' => $isP ? 8 : 5,  'r' => $isP ? 5 : 8]
            ];
        }
        else if (in_array($name, ["G", "O"])) {
            $isFixedLayout = true;
            $rowLetters = range('A', 'T');
            $maxCol = 20;
            $isO = ($name === "O");
            $matrix = [
                ['l' => $isO ? 0 : 17, 'r' => $isO ? 17 : 0], ['l' => $isO ? 0 : 16, 'r' => $isO ? 16 : 0], ['l' => $isO ? 0 : 16, 'r' => $isO ? 16 : 0], ['l' => $isO ? 0 : 15, 'r' => $isO ? 15 : 0],
                ['l' => $isO ? 0 : 15, 'r' => $isO ? 15 : 0], ['l' => $isO ? 0 : 15, 'r' => $isO ? 15 : 0], ['l' => $isO ? 0 : 15, 'r' => $isO ? 15 : 0], ['l' => $isO ? 0 : 5,  'r' => $isO ? 5 : 0],
                ['l' => $isO ? 0 : 5,  'r' => $isO ? 5 : 0],  ['l' => $isO ? 0 : 4,  'r' => $isO ? 4 : 0],  ['l' => $isO ? 0 : 4,  'r' => $isO ? 4 : 0],  ['l' => $isO ? 0 : 4,  'r' => $isO ? 4 : 0],
                ['l' => $isO ? 0 : 3,  'r' => $isO ? 3 : 0],  ['l' => $isO ? 0 : 3,  'r' => $isO ? 3 : 0],  ['l' => $isO ? 0 : 2,  'r' => $isO ? 2 : 0],  ['l' => $isO ? 0 : 2,  'r' => $isO ? 2 : 0],
                ['l' => $isO ? 0 : 1,  'r' => $isO ? 1 : 0],  ['l' => $isO ? 0 : 1,  'r' => $isO ? 1 : 0],  ['l' => $isO ? 0 : 1,  'r' => $isO ? 1 : 0],  ['l' => 0, 'r' => 0]
            ];
        }
        else if (in_array($name, ["H", "N"])) {
            $isFixedLayout = true;
            $rowLetters = range('A', 'U');
            $maxCol = 19;
            $isN = ($name === "N");
            $matrix = [
                ['l' => $isN ? 16 : 0, 'r' => $isN ? 0 : 16], ['l' => $isN ? 15 : 0, 'r' => $isN ? 0 : 15], ['l' => $isN ? 15 : 0, 'r' => $isN ? 0 : 15], ['l' => $isN ? 14 : 0, 'r' => $isN ? 0 : 14],
                ['l' => $isN ? 14 : 0, 'r' => $isN ? 0 : 14], ['l' => $isN ? 14 : 0, 'r' => $isN ? 0 : 14], ['l' => $isN ? 14 : 0, 'r' => $isN ? 0 : 14], ['l' => $isN ? 6 : 0,  'r' => $isN ? 0 : 6],
                ['l' => $isN ? 6 : 0,  'r' => $isN ? 0 : 6],  ['l' => $isN ? 5 : 0,  'r' => $isN ? 0 : 5],  ['l' => $isN ? 5 : 0,  'r' => $isN ? 0 : 5],  ['l' => $isN ? 4 : 0,  'r' => $isN ? 0 : 4],
                ['l' => $isN ? 3 : 0,  'r' => $isN ? 0 : 3],  ['l' => $isN ? 3 : 0,  'r' => $isN ? 0 : 3],  ['l' => $isN ? 2 : 0,  'r' => $isN ? 0 : 2],  ['l' => $isN ? 2 : 0,  'r' => $isN ? 0 : 2],
                ['l' => $isN ? 1 : 0,  'r' => $isN ? 0 : 1],  ['l' => $isN ? 1 : 0,  'r' => $isN ? 0 : 1],  ['l' => $isN ? 1 : 0,  'r' => $isN ? 0 : 1],  ['l' => 0, 'r' => 0], ['l' => 0, 'r' => 0]
            ];
        }
        else if (in_array($name, ["I", "M"])) {
            $isFixedLayout = true;
            $rowLetters = range('A', 'U');
            $maxCol = 20;
            $isM = ($name === "M");
            $matrix = [
                ['l' => $isM ? 0 : 17, 'r' => $isM ? 17 : 0], ['l' => $isM ? 0 : 16, 'r' => $isM ? 16 : 0], ['l' => $isM ? 0 : 16, 'r' => $isM ? 16 : 0], ['l' => $isM ? 0 : 15, 'r' => $isM ? 15 : 0],
                ['l' => $isM ? 0 : 15, 'r' => $isM ? 15 : 0], ['l' => $isM ? 0 : 15, 'r' => $isM ? 15 : 0], ['l' => $isM ? 0 : 15, 'r' => $isM ? 15 : 0], ['l' => $isM ? 0 : 5,  'r' => $isM ? 5 : 0],
                ['l' => $isM ? 0 : 5,  'r' => $isM ? 5 : 0],  ['l' => $isM ? 0 : 4,  'r' => $isM ? 4 : 0],  ['l' => $isM ? 0 : 4,  'r' => $isM ? 4 : 0],  ['l' => $isM ? 0 : 4,  'r' => $isM ? 4 : 0],
                ['l' => $isM ? 0 : 3,  'r' => $isM ? 3 : 0],  ['l' => $isM ? 0 : 3,  'r' => $isM ? 3 : 0],  ['l' => $isM ? 0 : 2,  'r' => $isM ? 2 : 0],  ['l' => $isM ? 0 : 2,  'r' => $isM ? 2 : 0],
                ['l' => $isM ? 0 : 1,  'r' => $isM ? 1 : 0],  ['l' => $isM ? 0 : 1,  'r' => $isM ? 1 : 0],  ['l' => $isM ? 0 : 1,  'r' => $isM ? 1 : 0],  ['l' => 0, 'r' => 0], ['l' => 0, 'r' => 0]
            ];
        }
        else if (in_array($name, ["J", "L"])) {
            $isFixedLayout = true;
            $rowLetters = range('A', 'W');
            $maxCol = 20;
            $isL = ($name === "L");
            $matrix = [
                ['l' => $isL ? 17 : 0, 'r' => $isL ? 0 : 17], ['l' => $isL ? 17 : 0, 'r' => $isL ? 0 : 17], ['l' => $isL ? 16 : 0, 'r' => $isL ? 0 : 16], ['l' => $isL ? 16 : 0, 'r' => $isL ? 0 : 16],
                ['l' => $isL ? 16 : 0, 'r' => $isL ? 0 : 16], ['l' => $isL ? 5 : 0,  'r' => $isL ? 0 : 5],  ['l' => $isL ? 5 : 0,  'r' => $isL ? 0 : 5],  ['l' => $isL ? 5 : 0,  'r' => $isL ? 0 : 5],
                ['l' => $isL ? 4 : 0,  'r' => $isL ? 0 : 4],  ['l' => $isL ? 4 : 0,  'r' => $isL ? 0 : 4],  ['l' => $isL ? 3 : 0,  'r' => $isL ? 0 : 3],  ['l' => $isL ? 3 : 0,  'r' => $isL ? 0 : 3],
                ['l' => $isL ? 2 : 0,  'r' => $isL ? 0 : 2],  ['l' => $isL ? 2 : 0,  'r' => $isL ? 0 : 2],  ['l' => $isL ? 1 : 0,  'r' => $isL ? 0 : 1],  ['l' => $isL ? 1 : 0,  'r' => $isL ? 0 : 1],
                ['l' => $isL ? 1 : 0,  'r' => $isL ? 0 : 1],  ['l' => 0, 'r' => 0], ['l' => $isL ? 11 : 0, 'r' => $isL ? 0 : 11], ['l' => $isL ? 7 : 0,  'r' => $isL ? 0 : 7],
                ['l' => $isL ? 7 : 0,  'r' => $isL ? 0 : 7],  ['l' => $isL ? 7 : 0,  'r' => $isL ? 0 : 7],  ['l' => $isL ? 6 : 0,  'r' => $isL ? 0 : 6]
            ];
        }
        else if ($name === "K") {
            $isFixedLayout = true;
            $rowLetters = ["AA", "A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q"];
            $maxCol = 14;
            $matrix = [
                ['l'=>2, 'r'=>2], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1],
                ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1],
                ['l'=>1, 'r'=>1], ['l'=>0, 'r'=>0]
            ];
        }

        // ============================================
        // 🎯 ศาลาดนตรีสุริยเทพ ม.รังสิต (RANGSIT HALL)
        // ============================================
        else if (in_array($name, ["ZONE 1", "ZONE1"])) {
            $isFixedLayout = true;
            $rowLetters = ["C", "D", "E", "F", "G", "H", "I", "J", "K", "L"];
            $maxCol = 20;
            $matrix = [
                ['l'=>2, 'r'=>6, 'gapAfter'=>8], ['l'=>1, 'r'=>6, 'gapAfter'=>9], ['l'=>2, 'r'=>6, 'gapAfter'=>8],
                ['l'=>3, 'r'=>5, 'gapAfter'=>8], ['l'=>4, 'r'=>4, 'gapAfter'=>7], ['l'=>5, 'r'=>2, 'gapAfter'=>6],
                ['l'=>5, 'r'=>2, 'gapAfter'=>6], ['l'=>12, 'r'=>1, 'gapAfter'=>0], ['l'=>5, 'r'=>3], ['l'=>5, 'r'=>3]
            ];
        }
        else if (in_array($name, ["ZONE 2", "ZONE2"])) {
            $isFixedLayout = true;
            $rowLetters = ["C", "D", "E", "F", "G", "H", "I", "J", "K", "L"];
            $maxCol = 22;
            $matrix = [
                ['l'=>6, 'r'=>2, 'start'=>12], ['l'=>6, 'r'=>1, 'start'=>13], ['l'=>6, 'r'=>2, 'start'=>12],
                ['l'=>5, 'r'=>2, 'start'=>12], ['l'=>5, 'r'=>1, 'start'=>12], ['l'=>5, 'r'=>2, 'start'=>12],
                ['l'=>6, 'r'=>2, 'start'=>13], ['l'=>1, 'r'=>6, 'start'=>8],  ['l'=>6, 'r'=>0, 'start'=>13], ['l'=>6, 'r'=>1, 'start'=>13]
            ];
        }
        else if (in_array($name, ["ZONE 3", "ZONE3"])) {
            $isFixedLayout = true;
            $rowLetters = ["C", "D", "E", "F", "G", "H", "I", "J", "K", "L"];
            $maxCol = 20;
            $matrix = [
                ['l'=>5, 'r'=>3, 'gapAfter'=>3, 'start'=>26], ['l'=>5, 'r'=>2, 'gapAfter'=>3, 'start'=>28], ['l'=>5, 'r'=>3, 'gapAfter'=>3, 'start'=>26],
                ['l'=>4, 'r'=>3, 'gapAfter'=>4, 'start'=>27], ['l'=>4, 'r'=>4, 'gapAfter'=>4, 'start'=>28], ['l'=>3, 'r'=>5, 'gapAfter'=>5, 'start'=>27],
                ['l'=>2, 'r'=>5, 'gapAfter'=>6, 'start'=>27], ['l'=>1, 'r'=>11, 'gapAfter'=>7, 'start'=>23], ['l'=>2, 'r'=>6, 'start'=>29], ['l'=>3, 'r'=>5, 'start'=>28]
            ];
        }
        else if (in_array($name, ["ZONE 4", "ZONE4"])) {
            $isFixedLayout = true;
            $rowLetters = ["M", "N", "O", "P", "Q", "R", "S", "T", "U", "V"];
            $maxCol = 16;
            $matrix = [
                ['l'=>3, 'r'=>2], ['l'=>2, 'r'=>3], ['l'=>2, 'r'=>2], ['l'=>2, 'r'=>2],
                ['l'=>2, 'r'=>2], ['l'=>3, 'r'=>2], ['l'=>4, 'r'=>1], ['l'=>4, 'r'=>1],
                ['l'=>4, 'r'=>1], ['l'=>4, 'r'=>1]
            ];
        }
        else if (in_array($name, ["ZONE 5", "ZONE5"])) {
            $isFixedLayout = true;
            $rowLetters = ["M", "N", "O", "P", "Q", "R", "S", "T"];
            $maxCol = 20;
            $matrix = [
                ['l'=>3, 'r'=>3, 'start'=>12], ['l'=>2, 'r'=>3, 'start'=>12], ['l'=>3, 'r'=>1, 'start'=>13],
                ['l'=>3, 'r'=>2, 'start'=>13], ['l'=>3, 'r'=>1, 'start'=>13], ['l'=>2, 'r'=>3, 'start'=>12],
                ['l'=>3, 'r'=>1, 'start'=>12], ['l'=>2, 'r'=>3, 'start'=>12]
            ];
        }
        else if (in_array($name, ["ZONE 6", "ZONE6"])) {
            $isFixedLayout = true;
            $rowLetters = ["M", "N", "O", "P", "Q", "R", "S", "T", "U", "V"];
            $maxCol = 16;
            $matrix = [
                ['l'=>2, 'r'=>3, 'start'=>26], ['l'=>3, 'r'=>2, 'start'=>27], ['l'=>2, 'r'=>2, 'start'=>29],
                ['l'=>2, 'r'=>2, 'start'=>28], ['l'=>2, 'r'=>2, 'start'=>29], ['l'=>3, 'r'=>2, 'start'=>27],
                ['l'=>2, 'r'=>3, 'start'=>28], ['l'=>3, 'r'=>2, 'start'=>27], ['l'=>2, 'r'=>3, 'start'=>26], ['l'=>3, 'r'=>2, 'start'=>27]
            ];
        }
        else if (in_array($name, ["ZONE 7", "ZONE7"])) {
            $isFixedLayout = true;
            $rowLetters = ["AA", "BB", "CC", "DD", "EE", "FF", "GG", "HH"];
            $maxCol = 11;
            $matrix = array_fill(0, 8, ['l'=>0, 'r'=>0]);
        }
        else if (in_array($name, ["ZONE 8", "ZONE8"])) {
            $isFixedLayout = true;
            $rowLetters = ["AA", "BB", "CC", "DD", "EE", "FF", "GG", "HH"];
            $maxCol = 16;
            $matrix = [
                ['l'=>0, 'r'=>0, 'start'=>12], ['l'=>1, 'r'=>0, 'start'=>12],
                ['l'=>0, 'r'=>0, 'start'=>12], ['l'=>1, 'r'=>0, 'start'=>12],
                ['l'=>0, 'r'=>0, 'start'=>12], ['l'=>1, 'r'=>0, 'start'=>12],
                ['l'=>0, 'r'=>0, 'start'=>12], ['l'=>1, 'r'=>0, 'start'=>12]
            ];
        }
        else if (in_array($name, ["ZONE 9", "ZONE9"])) {
            $isFixedLayout = true;
            $rowLetters = ["AA", "BB", "CC", "DD", "EE", "FF", "GG", "HH"];
            $maxCol = 11;
            $matrix = [
                ['l'=>0, 'r'=>0, 'start'=>28], ['l'=>0, 'r'=>0, 'start'=>27], ['l'=>0, 'r'=>0, 'start'=>28], ['l'=>0, 'r'=>0, 'start'=>27],
                ['l'=>0, 'r'=>0, 'start'=>28], ['l'=>0, 'r'=>0, 'start'=>27], ['l'=>0, 'r'=>0, 'start'=>28], ['l'=>0, 'r'=>0, 'start'=>27]
            ];
        }
        
        // ถ้าไม่ตรงกับโซนใดๆ ด้านบน ให้ส่ง null เพื่อไปสร้างเป็นรูปทรงสี่เหลี่ยมธรรมดา
        if (!$isFixedLayout) {
            return null;
        }

        $seats = [];
        
        // จำลองการไล่ลูปและคำนวณเบอร์เก้าอี้แบบเดียวกับหน้า React เด๊ะๆ
        for ($rowIndex = 0; $rowIndex < count($rowLetters); $rowIndex++) {
            $baseLetter = $rowLetters[$rowIndex % count($rowLetters)];
            $cycle = floor($rowIndex / count($rowLetters));
            $rowLetter = $cycle > 0 ? $baseLetter . ($cycle + 1) : $baseLetter;

            $conf = $matrix[$rowIndex] ?? (count($matrix) > 0 ? end($matrix) : ['l'=>0, 'r'=>0]);
            
            $gapCount = isset($conf['gapAfter']) ? 1 : 0;
            $lBlanks = $conf['l'] ?? 0;
            $rBlanks = $conf['r'] ?? 0;
            
            $maxReal = $maxCol - $lBlanks - $rBlanks - $gapCount;
            $realToPlace = $maxReal;

            for ($i = 0; $i < $realToPlace; $i++) {
                $cleanName = trim($name);
                
                // ตรวจสอบทิศทางการนับเลขเหมือนหน้าบ้าน
                $isRightSideZone = in_array($cleanName, ["SK", "SL", "SM", "SN", "SJ", "SI", "J", "L", "K", "M", "N", "O", "P", "Q", "R", "S", "T"]);
                $isRangsitZone = strpos($cleanName, "ZONE") !== false;

                if ($isRightSideZone) {
                    // ฝั่งขวานับถอยหลัง
                    $seatNum = $realToPlace - $i;
                } else if ($isRangsitZone && isset($conf['start'])) {
                    // โซนรังสิตที่ระบุเลขเริ่ม
                    $seatNum = $conf['start'] + $i;
                } else {
                    // โซนทั่วไป
                    $seatNum = $startNum + $lBlanks + $i;
                }

                $seats[] = [
                    'row' => $rowLetter,
                    'num' => str_pad($seatNum, 2, '0', STR_PAD_LEFT) // เติม 0 นำหน้าให้เป็น 2 หลัก เช่น 01, 02
                ];
            }
        }

        return $seats;
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