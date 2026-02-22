<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('seats', function (Blueprint $table) {
            $table->id('Seat_id');
            $table->foreignId('Zone_id')->references('Zone_id')->on('ticket_zones')->onDelete('cascade');
            $table->string('SeatRow', 5);
            $table->string('SeatNo', 6);
            $table->enum('SeatStatus', ['ว่าง', 'กำลังจอง', 'จองแล้ว', 'ชำรุด/ใช้ไม่ได้'])->default('ว่าง');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seats');
    }
};
