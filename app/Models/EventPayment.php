<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventPayment extends Model
{
    use HasFactory;
    protected $table = 'event_payments';
    protected $primaryKey = 'EventPayment_id';

    protected $fillable = [
        'Event_id',
        'PayDate',
        'eventAmount',
        'payStatus'
    ];
}