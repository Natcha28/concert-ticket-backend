<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReleaseExpiredSeats extends Command
{
    // ชื่อคำสั่งเวลาเราจะเทสด้วยมือ
    protected $signature = 'seats:release';

    // คำอธิบายคำสั่ง
    protected $description = 'ค้นหาและคืนที่นั่งสำหรับบิลที่หมดเวลาชำระเงิน (เกิน 5 นาที)';

    public function handle()
    {
        // 1. หาบิลที่สถานะ 'รอการชำระเงิน' และสร้างมาแล้วเกิน 5 นาที
        $expiredBookings = Booking::where('BKStatus', 'รอการชำระเงิน')
            ->where('created_at', '<', Carbon::now()->subMinutes(5))
            ->get();

        $count = 0;

        foreach ($expiredBookings as $booking) {
            DB::transaction(function () use ($booking, &$count) {
                // เปลี่ยนสถานะบิล
                $booking->update(['BKStatus' => 'ยกเลิก']);
                DB::table('booking_details')->where('Booking_id', $booking->Booking_id)->update(['ETStatus' => 'ถูกยกเลิก (หมดเวลา)']);

                // คืนสถานะที่นั่ง
                $seatIds = DB::table('booking_details')->where('Booking_id', $booking->Booking_id)->pluck('Seat_id');
                if ($seatIds->count() > 0) {
                    DB::table('seats')->whereIn('Seat_id', $seatIds)->update(['SeatStatus' => 'ว่าง']);
                }

                // คืนโควต้าโซน
                DB::table('ticket_zones')->where('Zone_id', $booking->Zone_id)->increment('remainSeat', $booking->quantity);
                
                $count++;
            });
        }

        // พิมพ์แจ้งเตือนใน Console เวลาทำงานเสร็จ
        $this->info("ตรวจสอบเสร็จสิ้น: ยกเลิกและคืนที่นั่งไปทั้งหมด {$count} รายการ");
    }
}