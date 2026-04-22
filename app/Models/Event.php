<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;


class Event extends Model
{
    use HasFactory;

    protected $table = 'events';
    protected $primaryKey = 'Event_id';

    protected $fillable = [
        'Org_id',
        'Hall_id',
        'eventName',
        'eventDescription',
        'bannerImage',
        'MaxTicketsPerMember',
        'eventStatus',
        'rental_start',
        'rental_end'
    ];

    // ✅ เพิ่มตัวนี้ เพื่อให้ Laravel แนบฟิลด์ calculated_status ส่งไปให้ API ด้วยเสมอ
    protected $appends = ['calculated_status'];

    // 💡 Accessor สูตรคำนวณกลาง ตามเงื่อนไข 8 ข้อ
    public function getCalculatedStatusAttribute()
    {
        $now = Carbon::now('Asia/Bangkok');
        
        // เงื่อนไข 1, 2, 8: ถ้าเป็นสถานะที่รอการจัดการจากแอดมินหรือถูกยกเลิก จะข้ามการเช็คเวลาและให้แสดงผลตามนั้น
        if (in_array($this->eventStatus, ['กำลังเตรียม', 'รอชำระเงิน', 'ยกเลิกงาน', 'draft', 'pending', 'rejected', 'APPROVED'])) {
            return $this->eventStatus;
        }

        // ดึงรอบแรกมาเช็คเวลา
        $firstRound = $this->event_date_times()->orderBy('Sale_startDT', 'asc')->first();
        
        if ($firstRound) {
            $eventStart = Carbon::parse($firstRound->startDT);
            $eventEnd   = Carbon::parse($firstRound->endDT);
            $saleStart  = Carbon::parse($firstRound->Sale_startDT);
            $saleEnd    = $firstRound->Sale_endDT ? Carbon::parse($firstRound->Sale_endDT) : null;

            // เงื่อนไข 7: เสร็จสิ้น (เลยเวลาจัดคอนเสิร์ตแล้ว)
            if ($now->greaterThanOrEqualTo($eventEnd)) {
                return 'เสร็จสิ้น';
            } 
            
            // เงื่อนไข 6: กำลังจัด (ขณะนี้คือเวลาจัดคอนเสิร์ตอยู่)
            elseif ($now->between($eventStart, $eventEnd)) {
                return 'กำลังจัด';
            } 
            
            // เงื่อนไข 6 (ซ้ำ): บัตรขายหมด (ขายหมดเรียบร้อย ไม่เหลือบัตรแล้ว)
            // เช็คว่ามีโซนที่นั่งถูกสร้างไว้ และผลรวม remainSeat เหลือ 0
            elseif ($this->ticket_zones()->count() > 0 && $this->ticket_zones()->sum('remainSeat') <= 0) {
                return 'บัตรขายหมด';
            } 
            
            // เงื่อนไข 5: ปิดการขาย (หมดเวลาขายบัตร แต่ยังไม่ถึงเวลาคอนเสิร์ต)
            elseif ($saleEnd && $now->greaterThanOrEqualTo($saleEnd)) {
                return 'ปิดการขาย';
            } 
            
            // เงื่อนไข 4: เปิดขาย (ตามวันเวลาที่กำหนด)
            elseif ($now->greaterThanOrEqualTo($saleStart) && (!$saleEnd || $now->lessThan($saleEnd))) {
                return 'เปิดขาย';
            } 
            
            // เงื่อนไข 3: กำลังจะจัด (ชำระเงินสำเร็จแล้ว รอเวลาถึงคิวเปิดขายบัตร)
            else {
                return 'กำลังจะจัด';
            }
        }

        return $this->eventStatus; // คืนค่าดั้งเดิมถ้าหาเวลาไม่เจอ
    }

    // 1. เชื่อมกับตาราง Halls (สถานที่)
    public function hall()
    {
        return $this->belongsTo(Hall::class, 'Hall_id', 'Hall_id');
    }

    // 2. เชื่อมกับตาราง EventDateTimes (รอบการแสดง)
    public function event_date_times() 
    {
        return $this->hasMany(EventDateTime::class, 'Event_id', 'Event_id');
    }

    // 3. เชื่อมกับตาราง TicketZones (โซนที่นั่ง)
    public function ticket_zones()
    {
        return $this->hasMany(TicketZone::class, 'Event_id', 'Event_id');
    }

}