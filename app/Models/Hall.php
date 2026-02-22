<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Hall extends Model {
    use HasFactory;
    protected $table = 'halls';
    protected $primaryKey = 'Hall_id';

    // ✅ เพิ่มตรงนี้เพื่อให้ Seed ID 1 และข้อมูลอื่นๆ ได้
    protected $fillable = ['Hall_id', 'Hall_Name', 'Address', 'totalCapacity', 'LayoutImage'];
}