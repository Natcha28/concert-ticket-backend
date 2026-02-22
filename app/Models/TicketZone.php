<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketZone extends Model
{
    use HasFactory;

    protected $table = 'ticket_zones';
    protected $primaryKey = 'Zone_id';

    protected $fillable = [
        'Event_id',
        'HallZone_id',
        'zoneName',
        'colorZone',
        'priceperTick',
        'totalSeat',
        'remainSeat'
    ];
}