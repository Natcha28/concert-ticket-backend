<?php

namespace App\Models;

// นำเข้า Class ที่จำเป็น
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    // 1. ตั้งชื่อตารางให้ตรงกับ PgAdmin (มี s)
    protected $table = 'members';

    // 2. ตั้ง Primary Key ให้ตรง (ตัวเล็กตัวใหญ่ต้องเป๊ะ)
    protected $primaryKey = 'Mem_id';

    // 3. ปิด Timestamps เพราะในตารางไม่มี created_at, updated_at
    //public $timestamps = false;

    // 4. ระบุชื่อคอลัมน์ที่อนุญาตให้บันทึก (เช็คชื่อให้ตรงกับ PgAdmin เป๊ะๆ)
    protected $fillable = [
        'firstnameMB',
        'lastnameMB',
        'emailMB',
        'passwordMB',
        'personalID',
        'telMB',
        'gender',
        'statusMB',
    ];

    protected $hidden = [
        'passwordMB',
        'remember_token',
    ];

    // บอก Laravel ว่ารหัสผ่านอยู่ที่คอลัมน์ passwordMB
    public function getAuthPassword()
    {
        return $this->passwordMB;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'passwordMB' => 'hashed',
        ];
    }
}