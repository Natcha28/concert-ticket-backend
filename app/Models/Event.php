<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    // 1. เชื่อมกับตาราง Halls (สถานที่)
    public function hall()
    {
        return $this->belongsTo(Hall::class, 'Hall_id', 'Hall_id');
    }

    // 2. เชื่อมกับตาราง EventDateTimes (รอบการแสดง) ✅ เพิ่มอันนี้!
    public function event_date_times() 
    {
        return $this->hasMany(EventDateTime::class, 'Event_id', 'Event_id');
    }

    // 3. เชื่อมกับตาราง TicketZones (โซนที่นั่ง) ✅ เปลี่ยนชื่อให้ตรงกับ Frontend
    public function ticket_zones()
    {
        return $this->hasMany(TicketZone::class, 'Event_id', 'Event_id');
    }
}