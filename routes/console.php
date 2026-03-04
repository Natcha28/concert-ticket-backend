<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;

Schedule::call(function () {
    $expireTime = now()->subMinutes(10);
    $expiredBookings = DB::table('bookings')
        ->where('BKStatus', 'รอการชำระเงิน')
        ->where('created_at', '<', $expireTime)
        ->get();

    foreach ($expiredBookings as $booking) {
        DB::transaction(function () use ($booking) {
            DB::table('bookings')
                ->where('Booking_id', $booking->Booking_id)
                ->update(['BKStatus' => 'ยกเลิก', 'updated_at' => now()]);
            
            $seatIds = DB::table('booking_details')
                ->where('Booking_id', $booking->Booking_id)
                ->pluck('Seat_id');
            
            if ($seatIds->isNotEmpty()) {
                DB::table('seats')
                    ->whereIn('Seat_id', $seatIds)
                    ->update(['SeatStatus' => 'ว่าง', 'updated_at' => now()]);
            }
            
            DB::table('ticket_zones')
                ->where('Zone_id', $booking->Zone_id)
                ->increment('remainSeat', $booking->quantity);
        });
    }
})->everyMinute();