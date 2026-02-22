<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Hall;
use App\Models\Event;
use App\Models\EventDateTime;
use App\Models\TicketZone;
use App\Models\Booking;
use App\Models\BookingDetail;
use Carbon\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. สร้างผู้จัดงาน (Organizer)
        $org = Organizer::updateOrCreate(['emailOG' => 'org@test.com'], [
            'firstnameOG' => 'Somchai', 'lastnameOG' => 'Jaidee', 'compName' => 'GMM Grammy',
            'passwordOG' => Hash::make('12345678'), 'telOG' => '0811111111'
        ]);

        // 2. สร้างสถานที่ (Hall)
        $hall = Hall::updateOrCreate(['Hall_Name' => 'Impact Arena (Large)'], [
            'Address' => 'Nonthaburi', 'totalCapacity' => 12000
        ]);

        // 3. สร้างโซนพื้นที่ในฮอลล์ (HallZone)
        DB::table('hall_zones')->updateOrInsert(
            ['Hall_id' => $hall->Hall_id, 'zoneName' => 'VIP1'],
            ['zoneCapacity' => 500]
        );
        $hzId = DB::table('hall_zones')->where('zoneName', 'VIP1')->value('HallZone_id');

        // 4. ✨ [จุดที่แก้ไข] สร้างสมาชิก (Member) และกำหนดลงตัวแปร $member
        $member = User::updateOrCreate(['emailMB' => 'admin@test.com'], [
            'firstnameMB' => 'Mossy', 
            'lastnameMB' => 'Admin', 
            'passwordMB' => Hash::make('12345678'),
            'personalID' => '1100000000001', 
            'telMB' => '0899999999', 
            'statusMB' => 'ใช้งานได้'
        ]);

        // 5. สร้างอีเวนต์ (Event)
        $event = Event::updateOrCreate(['eventName' => 'GMM Grammy RS: Hit 90s Concert'], [
            'Org_id' => $org->Org_id, 'Hall_id' => $hall->Hall_id, 'eventStatus' => 'กำลังจะจัด',
            'MaxTicketsPerMember' => 4, 'rental_start' => Carbon::now()->addDays(10), 'rental_end' => Carbon::now()->addDays(14)
        ]);

        // 6. สร้างรอบการแสดง (EventDateTime)
        $edt = EventDateTime::updateOrCreate(['Event_id' => $event->Event_id, 'roundNumber' => 1], [
            'startDT' => Carbon::now()->addDays(10)->setTime(19, 0), 'endDT' => Carbon::now()->addDays(10)->setTime(22, 0),
            'Sale_startDT' => Carbon::now()->subDay(), 'Sale_endDT' => Carbon::now()->addDays(9)
        ]);

        // 7. สร้างโซนบัตร (TicketZone)
        $zone = TicketZone::updateOrCreate(['Event_id' => $event->Event_id, 'zoneName' => 'VIP Zone A'], [
            'HallZone_id' => $hzId, 'priceperTick' => 5000, 'totalSeat' => 100, 'remainSeat' => 99, 'colorZone' => '#FFD700'
        ]);

        // 8. สร้างที่นั่ง (Seats) - อ้างอิงตามรูป image_61a05c.png
        $seatId = DB::table('seats')->insertGetId([
            'Zone_id'    => $zone->Zone_id,
            'SeatRow'    => 'A',
            'SeatNo'     => '1',
            'SeatStatus' => 'จองแล้ว',
            'created_at' => now(),
            'updated_at' => now()
        ], 'Seat_id');

        // 9. สร้างการจอง (Booking) - ใช้ตัวแปร $member จากข้อ 4
        $booking = Booking::create([
            'Mem_id'      => $member->Mem_id, 
            'Datetime_id' => $edt->Datetime_id, 
            'Zone_id'     => $zone->Zone_id,
            'BKDate'      => now(), 
            'quantity'    => 1, 
            'totalPrice'  => 5000, 
            'BKStatus'    => 'ชำระเงินแล้ว'
        ]);

        // 10. สร้างรายละเอียดตั๋ว (BookingDetail) - อ้างอิงตามรูป image_60b7bb.png
        BookingDetail::create([
            'Booking_id'       => $booking->Booking_id,
            'Seat_id'          => $seatId,
            'QRCode'           => 'BP-' . strtoupper(Str::random(10)),
            'issueDate'        => now(),
            'Price_Per_Ticket' => 5000,
            'ETStatus'         => 'ใช้งานได้'
        ]);
    }
}