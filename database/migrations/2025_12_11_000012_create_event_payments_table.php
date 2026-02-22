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
        Schema::create('event_payments', function (Blueprint $table) {
            $table->id('EventPayment_id');
            $table->foreignId('Event_id')->references('Event_id')->on('events')->onDelete('cascade');
            $table->dateTime('PayDate')->useCurrent();
            $table->integer('eventAmount');
            $table->enum('payStatus', ['ยังไม่ได้ชำระ', 'ชำระเรียบร้อยแล้ว', 'ชำระไม่สำเร็จ', 'ยกเลิกการชำระ']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_payments');
    }
};
