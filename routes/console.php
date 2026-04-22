<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

// =================================================================
// 1. ระบบยกเลิกการจองที่หมดเวลา (10 นาที) และคืนที่นั่งกลับสู่ระบบ
// =================================================================
Schedule::call(function () {
    $expireTime = now()->subMinutes(10);
    $expiredBookings = DB::table('bookings')
        ->where('BKStatus', 'รอการชำระเงิน')
        ->where('created_at', '<', $expireTime)
        ->get();

    foreach ($expiredBookings as $booking) {
        DB::transaction(function () use ($booking) {
            // อัปเดตสถานะ Booking เป็น 'ยกเลิก'
            DB::table('bookings')
                ->where('Booking_id', $booking->Booking_id)
                ->update(['BKStatus' => 'ยกเลิก', 'updated_at' => now()]);
            
            // หา Seat_id ที่ถูกจองใน Booking นี้
            $seatIds = DB::table('booking_details')
                ->where('Booking_id', $booking->Booking_id)
                ->pluck('Seat_id');
            
            // เปลี่ยนสถานะที่นั่งกลับเป็น 'ว่าง'
            if ($seatIds->isNotEmpty()) {
                DB::table('seats')
                    ->whereIn('Seat_id', $seatIds)
                    ->update(['SeatStatus' => 'ว่าง', 'updated_at' => now()]);
            }
            
            // คืนจำนวนโควต้าที่นั่งในโซน
            DB::table('ticket_zones')
                ->where('Zone_id', $booking->Zone_id)
                ->increment('remainSeat', $booking->quantity);
        });
    }
})->everyMinute();

// =================================================================
// 2. คำสั่งปลดล็อกที่นั่ง (จาก Command seats:release)
// =================================================================
Schedule::command('seats:release')->everyMinute();

// =================================================================
// 3. ✅ [เพิ่มใหม่] ระบบอัปเดตสถานะอีเวนต์อัตโนมัติ ตามเวลาจริง
// =================================================================
Schedule::command('events:update-status')->everyMinute();