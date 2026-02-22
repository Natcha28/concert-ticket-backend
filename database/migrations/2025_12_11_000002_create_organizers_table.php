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
        Schema::create('organizers', function (Blueprint $table) {
            $table->id('Org_id');
            $table->string('firstnameOG', 50);
            $table->string('lastnameOG', 50);
            $table->string('compName', 50)->nullable();
            $table->string('emailOG', 100);
            $table->string('passwordOG', 100);
            $table->string('telOG', 10);
            $table->dateTime('registerdateOG')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizers');
    }
};
