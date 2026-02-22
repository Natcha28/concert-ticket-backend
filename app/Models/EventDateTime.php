<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventDateTime extends Model
{
    use HasFactory;

    protected $table = 'event_date_times';
    protected $primaryKey = 'Datetime_id';

    // ✅ รวมเป็นอันเดียวให้สะอาด และครบถ้วนตาม Migration
    protected $fillable = [
        'Event_id',
        'roundNumber',
        'startDT',
        'endDT',
        'Sale_startDT', 
        'Sale_endDT'
    ];

    public function event()
    {
        return $this->belongsTo(Event::class, 'Event_id', 'Event_id');
    }
}