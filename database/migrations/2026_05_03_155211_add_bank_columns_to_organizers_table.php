<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        // Schema::table คือการเข้าไป "แก้ไข" ตารางเดิม (ไม่ลบของเก่า)
        Schema::table('organizers', function (Blueprint $table) {
            // เพิ่มคอลัมน์ชื่อธนาคาร และเลขบัญชี
            // ->nullable() สำคัญมาก! คือการบอกว่า ข้อมูลเก่าที่มีอยู่แล้วให้เว้นช่องนี้เป็นค่าว่าง (NULL) ไปก่อนได้ จะได้ไม่ Error
            // ->after('telOG') คือการจัดระเบียบให้คอลัมน์ใหม่ไปต่อท้ายคอลัมน์เบอร์โทร
            $table->string('bank_name', 50)->nullable()->after('telOG');
            $table->string('bank_account', 20)->nullable()->after('bank_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('organizers', function (Blueprint $table) {
            // โค้ดส่วนนี้เอาไว้เผื่อเราพิมพ์คำสั่งย้อนกลับ (Rollback) มันจะลบแค่ 2 คอลัมน์นี้ทิ้ง
            $table->dropColumn(['bank_name', 'bank_account']);
        });
    }
};