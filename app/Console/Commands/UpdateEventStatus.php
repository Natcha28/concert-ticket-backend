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
            // ดึงข้อมูลเวลาแรกมาคำนวณ (กรณีมีหลายรอบ)
            $dateTime = $event->event_date_times->first();
            
            // ถ้างานไหนไม่มีการตั้งเวลาไว้ ให้ข้ามไปก่อน
            if (!$dateTime) continue;

            $oldStatus = $event->eventStatus;
            $expectedStatus = $oldStatus; // ค่าตั้งต้น

            // แปลงวันที่จาก DB เป็น Carbon Object เพื่อใช้เปรียบเทียบ
            $saleStart = Carbon::parse($dateTime->Sale_startDT);
            $saleEnd   = $dateTime->Sale_endDT ? Carbon::parse($dateTime->Sale_endDT) : null;
            $eventStart = Carbon::parse($dateTime->startDT);
            $eventEnd   = Carbon::parse($dateTime->endDT);

            // --- ลอจิกการตัดสินใจตามลำดับความสำคัญ (Priority) ---

            // 1. เช็ค "เสร็จสิ้น": ถ้าเลยเวลาจบงาน หรือเลยเวลาปิดขายบัตรแล้ว
            if ($now->greaterThanOrEqualTo($eventEnd) || ($saleEnd && $now->greaterThanOrEqualTo($saleEnd))) {
                $expectedStatus = 'เสร็จสิ้น';
            }
            // 2. เช็ค "กำลังจัด": ถ้าอยู่ในช่วงเวลาเริ่มโชว์จนถึงโชว์จบ
            elseif ($now->greaterThanOrEqualTo($eventStart) && $now->lessThan($eventEnd)) {
                $expectedStatus = 'กำลังจัด';
            }
            // 3. เช็ค "บัตรขายหมด": ถ้าที่นั่งรวมทุกโซน (remainSeat) เหลือ 0 หรือน้อยกว่า
            elseif ($event->ticket_zones->sum('remainSeat') <= 0) {
                $expectedStatus = 'บัตรขายหมด';
            }
            // 4. เช็ค "เปิดขายบัตร": ถ้าอยู่ในช่วงเวลาเริ่มขาย
            elseif ($now->greaterThanOrEqualTo($saleStart)) {
                $expectedStatus = 'เปิดขายบัตร';
            }
            // 5. สถานะเริ่มต้น: ถ้ายังไม่ถึงเวลาขาย
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