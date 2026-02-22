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
        Schema::create('bookings', function (Blueprint $table) {
            $table->id('Booking_id');
            $table->foreignId('Mem_id')->references('Mem_id')->on('members')->onDelete('cascade');
            $table->foreignId('Datetime_id')->references('Datetime_id')->on('event_date_times')->onDelete('cascade');
            $table->foreignId('Zone_id')->references('Zone_id')->on('ticket_zones')->onDelete('cascade');
            $table->dateTime('BKDate')->useCurrent();
            $table->integer('quantity');
            $table->integer('totalPrice');
            $table->enum('BKStatus', ['รอการชำระเงิน', 'ชำระเงินแล้ว', 'ยกเลิกการจอง', 'คืนเงินแล้ว']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
