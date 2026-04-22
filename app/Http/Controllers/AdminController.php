<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Organizer;
use Illuminate\Support\Facades\DB; 
use Carbon\Carbon; 

class AdminController extends Controller
{
    // ==========================================
    // 1. ฟังก์ชันดึงรายชื่อผู้จัดงานทั้งหมด (สำหรับหน้าตาราง)
    // ==========================================
    public function getOrganizers()
    {
        $organizers = Organizer::all();
        
        $allEvents = DB::table('events')->get();

        $formattedData = $organizers->map(function ($org) use ($allEvents) {
            $status = 'Active';
            $cleanFirstName = $org->firstnameOG;

            if (str_starts_with($org->firstnameOG, 'WAITING_')) {
                $status = 'Pending';
                $cleanFirstName = str_replace('WAITING_', '', $org->firstnameOG);
            } elseif (str_starts_with($org->firstnameOG, 'SUSPENDED_')) {
                $status = 'Suspended';
                $cleanFirstName = str_replace('SUSPENDED_', '', $org->firstnameOG);
            }

            $orgEvents = $allEvents->where('Org_id', $org->Org_id)->values();

            return [
                'id' => $org->Org_id,
                'org_code' => 'ORG-' . str_pad($org->Org_id, 4, '0', STR_PAD_LEFT), 
                'person_name' => $cleanFirstName . ' ' . $org->lastnameOG, 
                'company_name' => $org->compName,
                'email' => $org->emailOG,
                'phone' => $org->telOG,
                'event_count' => $orgEvents->count(), 
                'events' => $orgEvents, 
                'created_at' => $org->created_at,
                'status' => $status 
            ];
        });

        return response()->json($formattedData);
    }

    // ==========================================
    // ฟังก์ชันอัปเดตสถานะผู้จัดงานลง DB
    // ==========================================
    public function updateOrganizerStatus(Request $request, $id)
    {
        $organizer = Organizer::find($id);

        if (!$organizer) {
            return response()->json(['message' => 'ไม่พบข้อมูลผู้จัดงาน'], 404);
        }

        $action = $request->action; 
        $cleanName = str_replace(['WAITING_', 'SUSPENDED_'], '', $organizer->firstnameOG);

        if ($action === 'Active') {
            $organizer->update(['firstnameOG' => $cleanName]);
        } elseif ($action === 'Suspended') {
            $organizer->update(['firstnameOG' => 'SUSPENDED_' . $cleanName]);
        }

        return response()->json(['message' => 'อัปเดตสถานะใน Database สำเร็จแล้ว!']);
    } 

    // ==========================================
    // 2. ฟังก์ชันดึงรายละเอียดอีเวนต์ 1 งาน
    // ==========================================
    public function getEventDetail($id)
    {
        $event = DB::table('events')->where('Event_id', $id)->first();

        if (!$event) {
            return response()->json(['message' => 'ไม่พบข้อมูลอีเวนต์'], 404);
        }

        $organizer = DB::table('organizers')->where('Org_id', $event->Org_id)->first();
        $cleanFirstName = $organizer ? str_replace(['WAITING_', 'SUSPENDED_'], '', $organizer->firstnameOG) : '-';
        $orgName = $organizer ? $cleanFirstName . ' ' . $organizer->lastnameOG : 'ไม่พบข้อมูลผู้จัด';
        $orgCode = $organizer ? 'ORG-' . str_pad($organizer->Org_id, 4, '0', STR_PAD_LEFT) : '-';

        $formattedData = [
            'event' => [
                'id' => $event->Event_id,
                'name' => $event->eventName,
                'date' => $event->rental_start ?? 'ยังไม่ได้ระบุวันที่', 
                'location' => 'Hall ID: ' . ($event->Hall_id ?? '-'),
                'description' => $event->eventDescription ?? 'ไม่มีข้อมูลรายละเอียดงาน', 
                'status' => $event->eventStatus ?? 'Pending', 
            ],
            'organizer' => [
                'id' => $organizer ? $organizer->Org_id : null,
                'name' => $orgName,
                'code' => $orgCode,
            ],
            'sales' => [
                'total_tickets' => 0, 
                'sold_tickets' => 0,
                'total_revenue' => 0
            ]
        ];

        return response()->json($formattedData);
    }

    // ==========================================
    // 4. ดึงรายชื่อลูกค้าทั้งหมด 
    // ==========================================
    public function getAllMembers()
    {
        $members = \App\Models\User::where('lastnameMB', '!=', 'Admin')
                    ->get()
                    ->map(function ($user) {
            
            $bookingIds = DB::table('bookings')
                            ->where('Mem_id', $user->Mem_id)
                            ->where('BKStatus', 'ชำระเงินแล้ว')
                            ->pluck('Booking_id');

            $totalSpent = DB::table('payments')
                            ->whereIn('Booking_id', $bookingIds)
                            ->where('PMStatus', 'ชำระเรียบร้อยแล้ว')
                            ->sum('Amount');

            $userBookings = DB::table('bookings')
                            ->where('Mem_id', $user->Mem_id)
                            ->orderBy('BKDate', 'desc')
                            ->get();

            $orders = $userBookings->map(function ($bk) {
                $eventName = 'Booking #' . $bk->Booking_id;
                $zoneName = 'Zone ID: ' . $bk->Zone_id;
                $venueName = '-'; 
                $posterUrl = '';  
                $seatDisplay = $bk->quantity . ' ใบ'; 
                
                try {
                    $datetime = DB::table('event_date_times')->where('Datetime_id', $bk->Datetime_id)->first();
                    if ($datetime) {
                        $event = DB::table('events')->where('Event_id', $datetime->Event_id)->first();
                        if ($event) {
                            $eventName = $event->eventName ?? $eventName;
                            $posterUrl = $event->event_image ?? $event->event_poster ?? ''; 
                            
                            if (!empty($event->Hall_id)) {
                                $hall = DB::table('halls')->where('Hall_id', $event->Hall_id)->first();
                                if ($hall) {
                                    $venueName = $hall->hallName ?? $hall->HallName ?? 'Hall ID: ' . $event->Hall_id;
                                }
                            }
                        }
                    }
                    
                    $zone = DB::table('ticket_zones')->where('Zone_id', $bk->Zone_id)->first();
                    if ($zone) {
                        $zoneName = $zone->zoneName ?? $zone->ZoneName ?? 'Zone ' . $bk->Zone_id;
                    }

                    $seats = DB::table('booking_details')
                                ->join('seats', 'booking_details.Seat_id', '=', 'seats.Seat_id')
                                ->where('booking_details.Booking_id', $bk->Booking_id)
                                ->get();

                    if ($seats->count() > 0) {
                        $seatNames = [];
                        foreach ($seats as $seat) {
                            $name = $seat->seatName ?? $seat->SeatName ?? '';
                            if (!empty($name)) $seatNames[] = $name;
                        }
                        if (count($seatNames) > 0) $seatDisplay = implode(', ', $seatNames);
                    }
                } catch (\Exception $e) {}

                $status = 'Pending';
                if ($bk->BKStatus === 'ชำระเงินแล้ว') $status = 'Paid';
                elseif ($bk->BKStatus === 'ยกเลิก') $status = 'Cancelled';

                return [
                    'id' => 'ORD-' . str_pad($bk->Booking_id, 4, '0', STR_PAD_LEFT),
                    'event' => $eventName,
                    'seat' => $zoneName . ' (' . $seatDisplay . ')',
                    'price' => (float) $bk->totalPrice,
                    'date' => !empty($bk->BKDate) ? \Carbon\Carbon::parse($bk->BKDate)->format('d M Y') : '-',
                    'time' => !empty($bk->BKDate) ? \Carbon\Carbon::parse($bk->BKDate)->format('H:i') : '-',
                    'venue' => $venueName, 
                    'status' => $status,
                    'poster' => $posterUrl
                ];
            })->toArray();

            $currentStatus = trim($user->statusMB);

            return [
                'id' => $user->Mem_id,
                'name' => $user->firstnameMB . ' ' . $user->lastnameMB,
                'email' => $user->emailMB,
                'role' => 'Member',
                'status' => $currentStatus === 'ใช้งานได้' ? 'Active' : 'Banned',
                'joined' => !empty($user->registerdateMB) ? \Carbon\Carbon::parse($user->registerdateMB)->format('d M Y') : 'N/A', 
                'spent' => $totalSpent,
                'avatar' => mb_substr($user->firstnameMB, 0, 1),
                'phone' => $user->telMB,
                'birthDate' => null, 
                'orders' => $orders
            ];
        });

        return response()->json($members);
    }

    // ==========================================
    // 5. เปลี่ยนสถานะลูกค้า
    // ==========================================
    public function toggleMemberStatus($id)
    {
        $user = DB::table('members')->where('Mem_id', $id)->first();

        if (!$user) {
            return response()->json(['message' => 'ไม่พบข้อมูลลูกค้า'], 404);
        }

        $currentStatus = trim($user->statusMB);
        $newStatus = $currentStatus === 'ใช้งานได้' ? 'ระงับบัญชี' : 'ใช้งานได้';
        
        DB::table('members')->where('Mem_id', $id)->update(['statusMB' => $newStatus]);

        return response()->json([
            'message' => 'อัปเดตสถานะเรียบร้อย',
            'status' => $newStatus === 'ใช้งานได้' ? 'Active' : 'Banned'
        ]);
    }

    // =================================================================
    // ดึงข้อมูลสำหรับหน้า Admin Dashboard
    // =================================================================
    public function getDashboardStats()
    {
        try {
            $totalRevenue = \Illuminate\Support\Facades\DB::table('bookings')
                ->where('BKStatus', 'ชำระเงินแล้ว')
                ->sum('totalPrice');

            $totalTicketsSold = \Illuminate\Support\Facades\DB::table('bookings')
                ->where('BKStatus', 'ชำระเงินแล้ว')
                ->sum('quantity');
            $platformRevenue = $totalTicketsSold * 20;

            $totalUsers = \Illuminate\Support\Facades\DB::table('members')->count();

            $completedEvents = \Illuminate\Support\Facades\DB::table('events')
                ->where('rental_end', '<', now())
                ->count();

            $recentOrganizers = \Illuminate\Support\Facades\DB::table('organizers')
                ->orderBy('Org_id', 'desc')
                ->take(5)
                ->get();

            $recentTransactions = \Illuminate\Support\Facades\DB::table('bookings')
                ->leftJoin('members', 'bookings.Mem_id', '=', 'members.Mem_id')
                ->select('bookings.*', 'members.firstnameMB')
                ->orderBy('BKDate', 'desc')
                ->take(5)
                ->get();

            $eventsData = \Illuminate\Support\Facades\DB::table('events')
                ->where('rental_end', '>=', now()) 
                ->get();
                
            $topEvents = [];

            foreach ($eventsData as $e) {
                $totalSeats = \Illuminate\Support\Facades\DB::table('ticket_zones')
                    ->where('Event_id', $e->Event_id)
                    ->sum('totalSeat');

                if ($totalSeats > 0) {
                    $soldSeats = \Illuminate\Support\Facades\DB::table('bookings')
                        ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                        ->where('event_date_times.Event_id', $e->Event_id)
                        ->where('bookings.BKStatus', 'ชำระเงินแล้ว')
                        ->sum('bookings.quantity');

                    $eventRevenue = \Illuminate\Support\Facades\DB::table('bookings')
                        ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                        ->where('event_date_times.Event_id', $e->Event_id)
                        ->where('bookings.BKStatus', 'ชำระเงินแล้ว')
                        ->sum('bookings.totalPrice');

                    $topEvents[] = [
                        'id' => $e->Event_id,
                        'name' => mb_convert_encoding($e->eventName ?? 'ไม่ได้ระบุชื่องาน', 'UTF-8', 'UTF-8'),
                        'sold' => (int) $soldSeats,
                        'total' => (int) $totalSeats,
                        'status' => $e->eventStatus,
                        'revenue' => (float) $eventRevenue 
                    ];
                }
            }

            $todayStr = Carbon::today()->toDateString(); 
            
            $todayEventsData = \Illuminate\Support\Facades\DB::table('events')
                ->join('event_date_times', 'events.Event_id', '=', 'event_date_times.Event_id')
                ->whereDate('event_date_times.Sale_startDT', $todayStr) 
                ->select('events.Event_id', 'events.eventName', 'events.eventStatus')
                ->groupBy('events.Event_id', 'events.eventName', 'events.eventStatus') 
                ->get();
                
            $todayEvents = [];
            
            foreach ($todayEventsData as $e) {
                $totalSeats = \Illuminate\Support\Facades\DB::table('ticket_zones')
                    ->where('Event_id', $e->Event_id)
                    ->sum('totalSeat');
                    
                $soldSeats = \Illuminate\Support\Facades\DB::table('bookings')
                        ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                        ->where('event_date_times.Event_id', $e->Event_id)
                        ->where('bookings.BKStatus', 'ชำระเงินแล้ว')
                        ->sum('bookings.quantity');
                        
                $todayEvents[] = [
                    'id' => $e->Event_id,
                    'name' => mb_convert_encoding($e->eventName ?? 'ไม่ได้ระบุชื่องาน', 'UTF-8', 'UTF-8'),
                    'sold' => (int) $soldSeats,
                    'total' => (int) $totalSeats,
                    'status' => $e->eventStatus
                ];
            }

            $organizersData = \Illuminate\Support\Facades\DB::table('organizers')->get();
            $topOrganizers = [];

            foreach ($organizersData as $org) {
                $orgEvents = \Illuminate\Support\Facades\DB::table('events')->where('Org_id', $org->Org_id)->get();
                $eventCount = $orgEvents->count();

                if ($eventCount > 0) {
                    $totalOrgSeats = 0;
                    $totalOrgSold = 0;
                    $totalOrgRevenue = 0;
                    $orgEventsList = []; 

                    foreach ($orgEvents as $evt) {
                        $seats = \Illuminate\Support\Facades\DB::table('ticket_zones')->where('Event_id', $evt->Event_id)->sum('totalSeat');
                        $totalOrgSeats += $seats;

                        $sold = \Illuminate\Support\Facades\DB::table('bookings')
                            ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                            ->where('event_date_times.Event_id', $evt->Event_id)
                            ->where('bookings.BKStatus', 'ชำระเงินแล้ว')
                            ->sum('bookings.quantity');
                            
                        $rev = \Illuminate\Support\Facades\DB::table('bookings')
                            ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                            ->where('event_date_times.Event_id', $evt->Event_id)
                            ->where('bookings.BKStatus', 'ชำระเงินแล้ว')
                            ->sum('bookings.totalPrice');

                        $totalOrgSold += $sold;
                        $totalOrgRevenue += $rev;

                        $orgEventsList[] = [
                            'name' => mb_convert_encoding($evt->eventName ?? 'ไม่ได้ระบุชื่องาน', 'UTF-8', 'UTF-8'),
                            'sold' => (int) $sold,
                            'total' => (int) $seats,
                            'revenue' => (float) $rev,
                            'status' => $evt->eventStatus
                        ];
                    }

                    $successRate = $totalOrgSeats > 0 ? round(($totalOrgSold / $totalOrgSeats) * 100) : 0;
                    
                    $cleanName = str_replace(['WAITING_', 'SUSPENDED_'], '', $org->firstnameOG);
                    $finalName = !empty($org->compName) ? $org->compName : trim($cleanName . ' ' . $org->lastnameOG);

                    $topOrganizers[] = [
                        'id' => $org->Org_id,
                        'name' => mb_convert_encoding($finalName, 'UTF-8', 'UTF-8'),
                        'email' => $org->emailOG ?? '-', 
                        'phone' => $org->telOG ?? '-', 
                        'event_count' => $eventCount,
                        'success_rate' => $successRate,
                        'revenue' => (float) $totalOrgRevenue,
                        'events' => $orgEventsList 
                    ];
                }
            }

            usort($topOrganizers, function($a, $b) {
                return $b['event_count'] <=> $a['event_count'];
            });
            
            $topOrganizers = array_slice($topOrganizers, 0, 3);

            $completedEventsData = \Illuminate\Support\Facades\DB::table('events')
                ->where('rental_end', '<', now()) 
                ->orderBy('rental_end', 'desc') 
                ->get();
                
            $completedEventsList = [];

            foreach ($completedEventsData as $e) {
                $totalSeats = \Illuminate\Support\Facades\DB::table('ticket_zones')
                    ->where('Event_id', $e->Event_id)
                    ->sum('totalSeat');

                if ($totalSeats > 0) {
                    $soldSeats = \Illuminate\Support\Facades\DB::table('bookings')
                        ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                        ->where('event_date_times.Event_id', $e->Event_id)
                        ->where('bookings.BKStatus', 'ชำระเงินแล้ว')
                        ->sum('bookings.quantity');

                    $eventRevenue = \Illuminate\Support\Facades\DB::table('bookings')
                        ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                        ->where('event_date_times.Event_id', $e->Event_id)
                        ->where('bookings.BKStatus', 'ชำระเงินแล้ว')
                        ->sum('bookings.totalPrice');

                    $completedEventsList[] = [
                        'id' => $e->Event_id,
                        'name' => mb_convert_encoding($e->eventName ?? 'ไม่ได้ระบุชื่องาน', 'UTF-8', 'UTF-8'),
                        'sold' => (int) $soldSeats,
                        'total' => (int) $totalSeats,
                        'status' => $e->eventStatus,
                        'revenue' => (float) $eventRevenue 
                    ];
                }
            }

            return response()->json([
                'stats' => [
                    'revenue' => (float)$totalRevenue,
                    'platformRevenue' => (float)$platformRevenue, 
                    'users' => $totalUsers,
                    'events' => $completedEvents,
                ],
                'organizers' => $recentOrganizers,
                'transactions' => $recentTransactions,
                'topEvents' => $topEvents,
                'todayEvents' => $todayEvents,
                'topOrganizers' => $topOrganizers,
                'completedEventsList' => $completedEventsList 
            ], 200, [], JSON_UNESCAPED_UNICODE);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    // ==========================================
    // ดึงรายการคำสั่งซื้อทั้งหมด (สำหรับหน้าธุรกรรมการเงิน)
    // ==========================================
    public function getOrders()
    {
        try {
            $orders = \Illuminate\Support\Facades\DB::table('bookings')
                ->join('members', 'bookings.Mem_id', '=', 'members.Mem_id')
                ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                ->join('events', 'event_date_times.Event_id', '=', 'events.Event_id')
                ->select(
                    'bookings.Booking_id as id',
                    'members.firstnameMB', 
                    'members.lastnameMB',
                    'events.eventName as event',
                    'bookings.totalPrice as amount',
                    'bookings.BKDate as date', 
                    'bookings.BKStatus as status'
                )
                ->orderBy('bookings.BKDate', 'desc')
                ->get();

            $formattedOrders = $orders->map(function ($item) {
                return [
                    'id' => 'ORD-' . str_pad($item->id, 4, '0', STR_PAD_LEFT),
                    'user' => trim(($item->firstnameMB ?? '') . ' ' . ($item->lastnameMB ?? '')), 
                    'event' => $item->event,
                    'method' => 'บัตรเครดิต/พร้อมเพย์',
                    'amount' => $item->amount,
                    'date' => !empty($item->date) ? \Carbon\Carbon::parse($item->date)->format('d/m/Y H:i') : '-',
                    'status' => $item->status === 'ชำระเงินแล้ว' ? 'Success' : ($item->status === 'รอการชำระเงิน' ? 'Pending' : 'Failed')
                ];
            });

            return response()->json($formattedOrders);
            
        } catch (\Exception $e) {
            return response()->json(['error' => 'getOrders Error: ' . $e->getMessage()], 500);
        }
    }

    // ==========================================
    // ดึงรายการเบิกจ่ายเงินให้ผู้จัด (สำหรับหน้าธุรกรรมการเงิน)
    // ==========================================
    public function getPayouts()
    {
        try {
            $payouts = \Illuminate\Support\Facades\DB::table('events')
                ->join('organizers', 'events.Org_id', '=', 'organizers.Org_id')
                ->select(
                    'events.Event_id as id',
                    'organizers.firstnameOG', 
                    'organizers.lastnameOG',
                    'events.eventName as event',
                    'events.rental_end as date'
                )
                ->where('events.eventStatus', 'สิ้นสุดแล้ว')
                ->get();

            $formattedPayouts = $payouts->map(function ($item) {
                return [
                    'id' => $item->id,
                    'organizer' => trim(($item->firstnameOG ?? '') . ' ' . ($item->lastnameOG ?? '')), 
                    'event' => $item->event,
                    'amount' => 1500000, 
                    'bank' => 'KBANK •••• 8829',
                    'date' => !empty($item->date) ? \Carbon\Carbon::parse($item->date)->format('d/m/Y H:i') : '-',
                    'status' => 'Pending'
                ];
            });

            return response()->json($formattedPayouts);
            
        } catch (\Exception $e) {
            return response()->json(['error' => 'getPayouts Error: ' . $e->getMessage()], 500);
        }
    }

    // ==========================================
    // ดึงรายละเอียดคำสั่งซื้อ 1 รายการ (สำหรับหน้ารายละเอียดธุรกรรม)
    // ==========================================
    public function getOrderDetail($id)
    {
        try {
            $realId = (int) str_replace('ORD-', '', $id);

            $booking = \Illuminate\Support\Facades\DB::table('bookings')
                ->join('members', 'bookings.Mem_id', '=', 'members.Mem_id')
                ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                ->join('events', 'event_date_times.Event_id', '=', 'events.Event_id')
                ->join('ticket_zones', 'bookings.Zone_id', '=', 'ticket_zones.Zone_id')
                ->select(
                    'bookings.*', 
                    'members.firstnameMB', 'members.lastnameMB', 'members.emailMB', 'members.telMB',
                    'events.eventName', 
                    'ticket_zones.zoneName', 'ticket_zones.ticketPrice'
                )
                ->where('bookings.Booking_id', $realId)
                ->first();

            if (!$booking) {
                return response()->json(['message' => 'ไม่พบข้อมูลคำสั่งซื้อ'], 404);
            }

            $status = 'pending';
            if ($booking->BKStatus === 'ชำระเงินแล้ว') $status = 'success';
            if ($booking->BKStatus === 'ยกเลิก') $status = 'refunded';

            $subtotal = $booking->totalPrice;
            $serviceFee = 0; 
            $vat = 0; 

            $formattedData = [
                'id' => 'ORD-' . str_pad($booking->Booking_id, 4, '0', STR_PAD_LEFT),
                'status' => $status,
                'amount' => number_format($booking->totalPrice, 2, '.', ''),
                'date' => \Carbon\Carbon::parse($booking->BKDate)->format('d M Y'),
                'time' => \Carbon\Carbon::parse($booking->BKDate)->format('H:i น.'),
                'paymentMethod' => 'บัตรเครดิต/พร้อมเพย์', 
                'customer' => [
                    'name' => trim($booking->firstnameMB . ' ' . $booking->lastnameMB),
                    'email' => $booking->emailMB,
                    'phone' => $booking->telMB ?? '-',
                    'id' => 'MEM-' . str_pad($booking->Mem_id, 4, '0', STR_PAD_LEFT)
                ],
                'items' => [
                    [
                        'name' => mb_convert_encoding($booking->eventName, 'UTF-8', 'UTF-8'),
                        'type' => $booking->zoneName ?? 'ทั่วไป',
                        'price' => number_format($booking->ticketPrice ?? $booking->totalPrice, 2, '.', ''),
                        'qty' => (int) $booking->quantity
                    ]
                ],
                'fees' => [
                    'subtotal' => number_format($subtotal, 2, '.', ''),
                    'serviceFee' => number_format($serviceFee, 2, '.', ''),
                    'vat' => number_format($vat, 2, '.', ''),
                    'total' => number_format($subtotal + $serviceFee + $vat, 2, '.', '')
                ],
                'timeline' => [
                    [ 'status' => 'ทำรายการสั่งซื้อ', 'time' => \Carbon\Carbon::parse($booking->BKDate)->format('H:i น.'), 'completed' => true ],
                    [ 'status' => 'ชำระเงิน', 'time' => '-', 'completed' => $status === 'success' ],
                ]
            ];

            return response()->json($formattedData);
            
        } catch (\Exception $e) {
            return response()->json(['error' => 'getOrderDetail Error: ' . $e->getMessage()], 500);
        }
    }

    // ==========================================
    // ดึงสรุปรายได้แบบละเอียด (เพื่อไปโชว์ในการ์ดสีเขียวและ GMV)
    // ==========================================
    public function getFinanceSummary()
    {
        try {
            $totalTickets = DB::table('bookings')->where('BKStatus', 'ชำระเงินแล้ว')->sum('quantity');
            $serviceFeeRevenue = $totalTickets * 20;

            $events = DB::table('events')->get();
            $totalCommission = 0;
            $eventsBreakdown = [];

            foreach ($events as $event) {
                $capacity = DB::table('ticket_zones')->where('Event_id', $event->Event_id)->sum('totalSeat');
                
                if ($capacity > 0) {
                    $sold = DB::table('bookings')
                        ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                        ->where('event_date_times.Event_id', $event->Event_id)
                        ->where('bookings.BKStatus', 'ชำระเงินแล้ว')
                        ->sum('bookings.quantity');

                    if ($sold > 0) {
                        $eventTicketSales = DB::table('bookings')
                            ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                            ->where('event_date_times.Event_id', $event->Event_id)
                            ->where('bookings.BKStatus', 'ชำระเงินแล้ว')
                            ->sum('bookings.totalPrice');

                        $eventGMV = $eventTicketSales + ($sold * 20);

                        $percentSold = ($sold / $capacity) * 100;
                        $gpRate = 0;
                        if ($percentSold >= 90) $gpRate = 0.05;
                        elseif ($percentSold >= 70) $gpRate = 0.03;

                        $eventCommission = $eventTicketSales * $gpRate;
                        $totalCommission += $eventCommission;

                        $eventsBreakdown[] = [
                            'name' => mb_convert_encoding($event->eventName ?? 'ไม่ระบุชื่อ', 'UTF-8', 'UTF-8'),
                            'gmv' => $eventGMV, 
                            'revenue' => ($sold * 20) + $eventCommission, 
                            'percent' => round($percentSold, 1)
                        ];
                    }
                }
            }

            return response()->json([
                'total_net_revenue' => $serviceFeeRevenue + $totalCommission,
                'breakdown' => [
                    'service_fee' => $serviceFeeRevenue,
                    'commission' => $totalCommission,
                    'total_tickets' => $totalTickets,
                    'events' => $eventsBreakdown 
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ==========================================
    // ดึงรายการอีเวนต์ทั้งหมดพร้อมยอดขาย (สำหรับหน้าตาราง Events)
    // ==========================================
    public function getAdminEvents()
    {
        try {
            $events = DB::table('events')
                ->join('organizers', 'events.Org_id', '=', 'organizers.Org_id')
                ->select('events.*', 'organizers.firstnameOG', 'organizers.lastnameOG')
                ->get();

            $formattedEvents = $events->map(function ($event) {
                $totalCapacity = DB::table('ticket_zones')
                    ->where('Event_id', $event->Event_id)
                    ->sum('totalSeat');

                $soldCount = DB::table('bookings')
                    ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                    ->where('event_date_times.Event_id', $event->Event_id)
                    ->where('bookings.BKStatus', 'ชำระเงินแล้ว')
                    ->sum('bookings.quantity');

                $cleanName = str_replace(['WAITING_', 'SUSPENDED_'], '', $event->firstnameOG);

                return [
                    'id' => $event->Event_id,
                    'name' => $event->eventName,
                    'organizer' => trim($cleanName . ' ' . $event->lastnameOG),
                    'type' => $event->eventType ?? 'Concert',
                    'publish_date' => $event->created_at ? \Carbon\Carbon::parse($event->created_at)->format('d M Y') : '-',
                    'status' => $event->eventStatus,
                    'sold_tickets' => (int)$soldCount,
                    'total_tickets' => (int)$totalCapacity,
                    'image' => $event->event_image ?? ($event->event_poster ?? '')
                ];
            });

            return response()->json($formattedEvents);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ==========================================
    // ดึงรายละเอียดอีเวนต์เจาะลึกรายโซน (สำหรับ Modal)
    // ==========================================
    public function getEventSalesDetail($id)
    {
        try {
            $zones = DB::table('ticket_zones')
                ->where('Event_id', $id)
                ->get();

            $zoneData = $zones->map(function ($zone) {
                // 1. หาจำนวนใบที่ขายได้ในโซนนี้
                $soldInZone = DB::table('bookings')
                    ->where('Zone_id', $zone->Zone_id)
                    ->where('BKStatus', 'ชำระเงินแล้ว')
                    ->sum('quantity');

                // 2. หารายได้ของโซนนี้จากตาราง bookings โดยตรง (ชัวร์ 100%)
                $revenueInZone = DB::table('bookings')
                    ->where('Zone_id', $zone->Zone_id)
                    ->where('BKStatus', 'ชำระเงินแล้ว')
                    ->sum('totalPrice');

                // ดักจับชื่อคอลัมน์ความจุ (Capacity)
                $capacity = $zone->totalSeat ?? $zone->TotalSeat ?? $zone->total_seat ?? $zone->capacity ?? 0;
                
                // ดักจับชื่อคอลัมน์ชื่อโซน
                $zoneName = $zone->zoneName ?? $zone->ZoneName ?? $zone->zone_name ?? 'Zone';

                // 3. คำนวณราคาต่อใบ (ถ้าระบบดึงจาก DB ไม่ได้ ให้เอารายได้รวม หารด้วย จำนวนใบซะเลย)
                $dbPrice = $zone->ticketPrice ?? $zone->TicketPrice ?? $zone->ticket_price ?? $zone->price ?? $zone->Price ?? 0;
                $price = $dbPrice > 0 ? $dbPrice : ($soldInZone > 0 ? $revenueInZone / $soldInZone : 0);

                return [
                    'zone_name' => $zoneName,
                    'price' => (float)$price,
                    'sold' => (int)$soldInZone,
                    'capacity' => (int)$capacity,
                    'revenue' => (float)$revenueInZone
                ];
            });

            return response()->json([
                'event_id' => $id,
                'zones' => $zoneData,
                'total_revenue' => $zoneData->sum('revenue')
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}