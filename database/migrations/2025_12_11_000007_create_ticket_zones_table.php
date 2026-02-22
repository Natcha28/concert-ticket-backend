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
        Schema::create('ticket_zones', function (Blueprint $table) {
            $table->id('Zone_id');
            $table->foreignId('Event_id')->references('Event_id')->on('events')->onDelete('cascade');
            $table->foreignId('HallZone_id')->references('HallZone_id')->on('hall_zones')->onDelete('cascade');
            $table->string('zoneName', 30);
            $table->string('colorZone', 20);
            $table->integer('priceperTick');
            $table->integer('totalSeat');
            $table->integer('remainSeat');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_zones');
    }
};
