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
        Schema::create('hall_zones', function (Blueprint $table) {
            $table->id('HallZone_id');
            $table->foreignId('Hall_id')->references('Hall_id')->on('halls')->onDelete('cascade');
            $table->string('zoneName', 50);
            $table->integer('zoneCapacity');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hall_zones');
    }
};
