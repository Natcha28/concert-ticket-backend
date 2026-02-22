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
        Schema::create('event_date_times', function (Blueprint $table) {
            $table->id('Datetime_id');
            $table->foreignId('Event_id')->references('Event_id')->on('events')->onDelete('cascade');
            $table->integer('roundNumber');
            $table->dateTime('startDT');
            $table->dateTime('endDT');
            $table->dateTime('Sale_startDT');

            $table->dateTime('Sale_endDT')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('event_date_times');
    }
};
