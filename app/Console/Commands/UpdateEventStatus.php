<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Event; 
use Carbon\Carbon;

class UpdateEventStatus extends Command
{
    /**
     * ชื่อคำสั่งที่ใช้รันใน Terminal
     */
    protected $signature = 'events:update-status';

    /**
     * คำอธิบายคำสั่ง
     */
    protected $description = 'ซิงโครไนซ์สถานะคอนเสิร์ตทั้งหมดตามเวลาจริงและจำนวนบัตร';

    public function handle()
    {
        // 1. ตั้งค่าเวลาปัจจุบันเป็นโซนไทย
        $now = Carbon::now('Asia/Bangkok');
        $this->comment("Checking events at: " . $now->toDateTimeString());

        // 2. ดึงข้อมูล Event ทั้งหมดพร้อมตารางเวลาและโซนที่นั่ง
        $events = Event::with(['event_date_times', 'ticket_zones'])->get();

        $updatedCount = 0;

        foreach ($events as $event) {
            $oldStatus = $event->eventStatus;
            
            // 🚨 ข้ามการอัปเดต ถ้างานอยู่ในสถานะตั้งต้นที่ต้องใช้คนจัดการ (เงื่อนไข 1, 2, 8)
            if (in_array($oldStatus, ['กำลังเตรียม', 'รอชำระเงิน', 'ยกเลิกงาน', 'draft', 'pending', 'rejected', 'APPROVED'])) {
                continue; 
            }

            // ดึงข้อมูลเวลาแรกมาคำนวณ
            $dateTime = $event->event_date_times->first();
            if (!$dateTime) continue;

            $expectedStatus = $oldStatus; // ค่าตั้งต้น

            // แปลงวันที่จาก DB เป็น Carbon Object
            $saleStart = Carbon::parse($dateTime->Sale_startDT);
            $saleEnd   = $dateTime->Sale_endDT ? Carbon::parse($dateTime->Sale_endDT) : null;
            $eventStart = Carbon::parse($dateTime->startDT);
            $eventEnd   = Carbon::parse($dateTime->endDT);

            // --- ลอจิกการตัดสินใจ ล้อตามเงื่อนไขจาก Model ---
            
            // 7. เสร็จสิ้น (เลยเวลาจัดคอนเสิร์ตแล้ว)
            if ($now->greaterThanOrEqualTo($eventEnd)) {
                $expectedStatus = 'เสร็จสิ้น';
            }
            // 6. กำลังจัด (ขณะนี้คือเวลาจัดคอนเสิร์ตอยู่)
            elseif ($now->between($eventStart, $eventEnd)) {
                $expectedStatus = 'กำลังจัด';
            }
            // 6(ซ้ำ). บัตรขายหมด 
            elseif ($event->ticket_zones->count() > 0 && $event->ticket_zones->sum('remainSeat') <= 0) {
                $expectedStatus = 'บัตรขายหมด';
            }
            // 5. ปิดการขาย (หมดเวลาขายบัตร แต่ยังไม่ถึงเวลาเริ่มงาน)
            elseif ($saleEnd && $now->greaterThanOrEqualTo($saleEnd)) {
                $expectedStatus = 'ปิดการขาย';
            }
            // 4. เปิดขาย (อยู่ในช่วงเวลาเริ่มขาย)
            elseif ($now->greaterThanOrEqualTo($saleStart) && (!$saleEnd || $now->lessThan($saleEnd))) {
                $expectedStatus = 'เปิดขาย';
            }
            // 3. กำลังจะจัด (ยังไม่ถึงเวลาขาย)
            else {
                $expectedStatus = 'กำลังจะจัด';
            }

            // 3. อัปเดตลงฐานข้อมูลเมื่อสถานะเปลี่ยนไปจากเดิม
            if ($oldStatus !== $expectedStatus) {
                $event->eventStatus = $expectedStatus;
                $event->save();
                $updatedCount++;
                
                $this->info("Event ID {$event->Event_id}: '{$oldStatus}' -> '{$expectedStatus}'");
            }
        }

        $this->info("---------------------------------------------");
        $this->info("Successfully synchronized {$updatedCount} events.");
    }
}