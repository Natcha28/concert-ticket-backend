<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Organizer extends Authenticatable
{
    use HasFactory, HasApiTokens;

    protected $table = 'organizers';
    protected $primaryKey = 'Org_id';

    public function getAuthPassword()
    {
        return $this->passwordOG;
    }

    // ✅ รวมคอลัมน์ให้ครบตาม Migration และใช้แค่บรรทัดเดียว
    protected $fillable = [
        'firstnameOG', 
        'lastnameOG', 
        'compName', 
        'emailOG', 
        'passwordOG', 
        'telOG',
        'registerdateOG'
    ];

    // ✅ ซ่อนรหัสผ่านไม่ให้แสดงผลออกมาตอนดึงข้อมูล (เพื่อความปลอดภัย)
    protected $hidden = [
        'passwordOG',
    ];
}