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
        Schema::create('payments', function (Blueprint $table) {
            $table->id('Pay_id');
            $table->foreignId('Booking_id')->references('Booking_id')->on('bookings')->onDelete('cascade');
            $table->dateTime('PMDate')->useCurrent();
            $table->integer('Amount');
            $table->enum('PMStatus', ['ยังไม่ได้ชำระ', 'ชำระเรียบร้อยแล้ว', 'ชำระไม่สำเร็จ', 'ยกเลิกการชำระ']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
