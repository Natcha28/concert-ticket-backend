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
        // ตาราง members
        Schema::create('members', function (Blueprint $table) {
            $table->id('Mem_id');
            $table->string('firstnameMB', 50);
            $table->string('lastnameMB', 50);
            $table->string('emailMB', 100)->unique();
            $table->string('passwordMB', 100);
            $table->string('personalID', 13)->nullable();
            $table->date('DateOfBirth')->nullable();
            $table->string('telMB', 10)->nullable();
            $table->enum('gender', ['ชาย', 'หญิง', 'ไม่ระบุ'])->nullable();
            $table->dateTime('registerdateMB')->useCurrent();
            $table->enum('statusMB', ['ใช้งานได้', 'ปิดบัญชี', 'ถูกระงับการใช้งาน'])->default('ใช้งานได้');
            $table->timestamps();
        });

        // ตาราง sessions
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('members');
    }
};
