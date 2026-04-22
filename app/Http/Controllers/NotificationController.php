<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Laravel\Sanctum\PersonalAccessToken; // ⭐️ เพิ่มบรรทัดนี้

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        try {
            // 1. ตรวจสอบ Token จาก Header
            $token = $request->bearerToken();
            if (!$token) {
                return response()->json([]); // ถ้าไม่ได้ล็อกอิน ไม่ต้องดึงข้อมูล
            }

            // 2. หาว่าใครเป็นเจ้าของ Token
            $accessToken = PersonalAccessToken::findToken($token);
            if (!$accessToken) {
                return response()->json([]); 
            }

            $userId = $accessToken->tokenable_id; // ได้ ID ลูกค้ามาแล้ว!

            // 3. ดึงประวัติการจอง 5 อันล่าสุด
            $recentBookings = DB::table('bookings')
                ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                ->join('events', 'event_date_times.Event_id', '=', 'events.Event_id')
                ->where('bookings.Mem_id', $userId)
                ->orderBy('bookings.updated_at', 'desc')
                ->limit(5)
                ->select('bookings.*', 'events.eventName')
                ->get();

            $notifications = [];

            // 4. สร้างแจ้งเตือนจากบิล
            foreach ($recentBookings as $booking) {
                Carbon::setLocale('th');
                $timeAgo = $booking->updated_at ? Carbon::parse($booking->updated_at)->diffForHumans() : 'เมื่อกี้';
                $orderNo = 'ORD-' . str_pad($booking->Booking_id, 4, '0', STR_PAD_LEFT);
                $uniqueId = $booking->Booking_id . '_' . strtotime($booking->updated_at);

                if ($booking->BKStatus === 'ชำระเงินแล้ว') {
                    $notifications[] = [
                        'id' => 'success_' . $uniqueId,
                        'type' => 'booking',
                        'title' => 'จองและชำระเงินสำเร็จ! ✅',
                        'description' => "ตั๋ว {$booking->eventName} ($orderNo) พร้อมใช้งานแล้ว",
                        'time_ago' => $timeAgo,
                        'link_url' => '/profile/tickets',
                        'is_read' => false 
                    ];
                } elseif ($booking->BKStatus === 'ยกเลิก') {
                    $notifications[] = [
                        'id' => 'cancel_' . $uniqueId,
                        'type' => 'alert',
                        'title' => 'ออเดอร์ถูกยกเลิก ❌',
                        'description' => "ออเดอร์ $orderNo ถูกยกเลิก (หมดเวลาชำระเงิน)",
                        'time_ago' => $timeAgo,
                        'link_url' => '/profile/history',
                        'is_read' => false
                    ];
                }
            }

            return response()->json($notifications, 200);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Backend Error: ' . $e->getMessage()], 500);
        }
    }
}