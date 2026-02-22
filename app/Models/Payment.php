<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;
    protected $table = 'payments';
    protected $primaryKey = 'Pay_id';


protected $fillable = [
        'Booking_id',
        'PMDate',
        'Amount',
        'PMStatus'
    ];
}