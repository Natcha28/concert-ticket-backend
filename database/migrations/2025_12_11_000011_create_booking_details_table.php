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
        Schema::create('booking_details', function (Blueprint $table) {
            $table->id('Bdetail_id');
            $table->foreignId('Booking_id')->references('Booking_id')->on('bookings')->onDelete('cascade');
            $table->foreignId('Seat_id')->references('Seat_id')->on('seats')->onDelete('cascade');
            $table->string('QRCode', 255)->nullable();
            $table->dateTime('issueDate')->nullable();
            $table->string('linkDownload', 255)->nullable();
            $table->integer('Price_Per_Ticket');
            $table->enum('ETStatus', ['ใช้งานได้', 'ใช้งานแล้ว', 'ถูกยกเลิก'])->default('ใช้งานได้');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_details');
    }
};
