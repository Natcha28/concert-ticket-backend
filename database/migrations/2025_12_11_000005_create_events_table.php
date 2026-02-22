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
        Schema::create('events', function (Blueprint $table) {
            $table->id('Event_id');
            $table->foreignId('Org_id')->references('Org_id')->on('organizers')->onDelete('cascade');
            $table->foreignId('Hall_id')->references('Hall_id')->on('halls')->onDelete('cascade');
            $table->string('eventName', 100);
            $table->string('eventDescription', 5000)->nullable();
            $table->string('bannerImage', 255)->nullable();

            $table->dateTime('rental_start')->nullable(); 
            $table->dateTime('rental_end')->nullable();
            
            $table->integer('MaxTicketsPerMember');
            $table->enum('eventStatus', ['กำลังเตรียม', 'กำลังจะจัด', 'กำลังจัดแสดง', 'เสร็จสิ้น', 'ยกเลิกงาน']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
