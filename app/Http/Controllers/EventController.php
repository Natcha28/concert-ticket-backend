<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Event;
use App\Models\EventDateTime;
use App\Models\TicketZone;
use App\Models\HallZone;
use App\Models\Seat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class EventController extends Controller
{
    // =================================================================
    // 1. หน้า "อีเวนต์ของฉัน" (My Events) - รายการงานของผู้จัด
    // =================================================================
    public function index()
    {
        $orgId = auth()->id() ?? 4; 
        $events = Event::with(['hall', 'ticket_zones', 'event_date_times']) 
                        ->where('Org_id', $orgId)
                        ->where('eventStatus', '!=', 'เสร็จสิ้น')
                        ->orderBy('created_at', 'desc')
                        ->get();

        $events->transform(function ($event) {
            // คำนวณที่นั่งเพื่อแสดงผล
            $totalSeats = $event->ticket_zones->sum('totalSeat');
            $remainSeats = $event->ticket_zones->sum('remainSeat');
            $soldSeats = $totalSeats - $remainSeats;
            
            // จัดการรูปภาพ
            $imageUrl = null;
            if ($event->bannerImage) {
                $imageUrl = str_starts_with($event->bannerImage, 'http') 
                    ? $event->bannerImage 
                    : asset('storage/' . $event->bannerImage);
            }

            return [
                'id'       => $event->Event_id,
                'title'    => $event->eventName,
                'date'     => $event->rental_start, 
                'location' => $event->hall->Hall_Name ?? 'ไม่ระบุสถานที่',
                // ✅ เรียกใช้สถานะ Real-time จากสูตรกลางของ Model แทนการคำนวณซ้ำใน Controller
                'status'   => $event->calculated_status, 
                'sold'     => $soldSeats,
                'capacity' => $totalSeats,
                'image'    => $imageUrl
            ];
        });
        
        return response()->json($events);
    }

    // =================================================================
    // 2. หน้า "ดูรายละเอียดงาน" (Event Detail)
    // =================================================================
    public function show($id)
    {
        $event = Event::with(['hall', 'event_date_times', 'ticket_zones']) 
                      ->where('Event_id', $id)
                      ->first();
        
        if (!$event) { 
            return response()->json(['message' => 'ไม่พบข้อมูลอีเวนต์'], 404); 
        }
        return response()->json($event);
    }

    // =================================================================
    // 3. ฟังก์ชัน "สร้างอีเวนต์ใหม่" (Create Event) + สร้างที่นั่งอัตโนมัติ
    // =================================================================
    public function store(Request $request)
    {
        DB::beginTransaction(); 
        try {
            $rounds = collect($request->show_rounds);
            if ($rounds->isEmpty()) {
                return response()->json(['message' => 'กรุณาระบุรอบการแสดงอย่างน้อย 1 รอบ'], 400);
            }

            $firstShowDate = Carbon::parse($rounds->min('startDT'));
            $lastShowDate  = Carbon::parse($rounds->max('endDT'));
            $rentalStart = $firstShowDate->copy()->subDays(2)->startOfDay(); 
            $rentalEnd   = $lastShowDate->copy()->addDays(2)->endOfDay();

            // 1. สร้าง Event
            $event = new Event();
            $event->Org_id = auth()->id() ?? 4; 
            $event->Hall_id = $request->Hall_id;
            $event->eventName = $request->eventName;
            
            $gridDataJson = json_encode(['venue_size' => $request->venueSize, 'stage_grid' => $request->stageGrid ?? []]);
            $event->eventDescription = $request->eventDescription . "||GRID_DATA||" . $gridDataJson;
            
            $event->bannerImage = str_starts_with($request->posterImage, 'data:image') 
                ? $this->saveBase64Image($request->posterImage, 'events') 
                : $request->posterImage;

            $event->MaxTicketsPerMember = 4;
            $event->eventStatus = 'กำลังเตรียม'; 
            $event->rental_start = $rentalStart;
            $event->rental_end   = $rentalEnd;
            $event->save();

            // 2. สร้างรอบการแสดง
            foreach ($request->show_rounds as $round) {
                EventDateTime::create([
                    'Event_id' => $event->Event_id,
                    'roundNumber' => $round['roundNumber'],
                    'startDT' => $round['startDT'],
                    'endDT' => $round['endDT'],
                    'Sale_startDT' => $round['saleStart'],
                    'Sale_endDT' => $round['saleEnd'] ?? null
                ]);
            }

            // 3. สร้างโซนและที่นั่ง
            if ($request->has('tickets')) {
                foreach ($request->tickets as $index => $t) {
                    $zoneName = strtoupper(trim($t['zoneName'] ?? 'Zone ' . ($index + 1)));
                    $fixedSeats = $this->getRachadalaiFixedSeats($zoneName);
                    $capacity = $fixedSeats ? count($fixedSeats) : ($t['quantity'] ?? 0);

                    $hallZone = HallZone::create([
                        'Hall_id' => $request->Hall_id,
                        'zoneName' => $zoneName,
                        'zoneCapacity' => $capacity
                    ]);

                    $ticketZone = TicketZone::create([
                        'Event_id' => $event->Event_id,
                        'HallZone_id' => $hallZone->HallZone_id,
                        'zoneName' => $zoneName,
                        'colorZone' => $t['color'] ?? '#FFFFFF',
                        'priceperTick' => $t['price'],
                        'totalSeat' => $capacity,
                        'remainSeat' => $capacity
                    ]);

                    $seatDataToInsert = [];
                    $now = now();

                    if ($fixedSeats) {
                        foreach ($fixedSeats as $seat) {
                            $seatDataToInsert[] = [
                                'Zone_id'    => $ticketZone->Zone_id,
                                'SeatRow'    => $seat['row'],
                                'SeatNo'     => $seat['num'],
                                'SeatStatus' => 'ว่าง',
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    } else {
                        $maxSeatsPerRow = 20; 
                        $currentQuantity = 0;
                        $rowIdx = 0;
                        $rowLetters = range('A', 'Z');

                        while ($currentQuantity < $capacity) {
                            $rowLetter = $rowLetters[$rowIdx] ?? 'Z' . $rowIdx;
                            for ($seatNum = 1; $seatNum <= $maxSeatsPerRow && $currentQuantity < $capacity; $seatNum++) {
                                $seatDataToInsert[] = [
                                    'Zone_id'    => $ticketZone->Zone_id,
                                    'SeatRow'    => $rowLetter,
                                    'SeatNo'     => str_pad($seatNum, 2, '0', STR_PAD_LEFT),
                                    'SeatStatus' => 'ว่าง',
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ];
                                $currentQuantity++;
                            }
                            $rowIdx++;
                        }
                    }

                    foreach (array_chunk($seatDataToInsert, 500) as $chunk) {
                        Seat::insert($chunk);
                    }
                }
            }

            DB::commit(); 
            return response()->json(['message' => 'สร้างอีเวนต์และที่นั่งเรียบร้อยแล้ว', 'id' => $event->Event_id], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    // =================================================================
    // 4. หน้า "รายชื่อผู้เข้าร่วม" (Attendees)
    // =================================================================
    public function getAttendees(Request $request)
    {
        $orgId = auth()->id() ?? 4; 
        try {
            $attendees = DB::table('bookings')
                ->join('members', 'bookings.Mem_id', '=', 'members.Mem_id') 
                ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                ->join('events', 'event_date_times.Event_id', '=', 'events.Event_id')
                ->join('ticket_zones', 'bookings.Zone_id', '=', 'ticket_zones.Zone_id')
                ->where('events.Org_id', $orgId)
                ->select(
                    'bookings.Booking_id as id',
                    'members.firstnameMB as firstname', 
                    'members.lastnameMB as lastname',
                    'members.emailMB as email',
                    'ticket_zones.zoneName as ticket',
                    'events.eventName as eventName',
                    'bookings.BKStatus as status',
                    // ✅ แก้ไขให้รองรับ PostgreSQL (ใช้ STRING_AGG แทน GROUP_CONCAT)
                    DB::raw("(SELECT STRING_AGG(CONCAT(seats.\"SeatRow\", seats.\"SeatNo\"), ', ') 
                             FROM booking_details 
                             JOIN seats ON booking_details.\"Seat_id\" = seats.\"Seat_id\" 
                             WHERE booking_details.\"Booking_id\" = bookings.\"Booking_id\") as seats")
                )
                ->orderBy('bookings.created_at', 'desc')
                ->get();

            $attendees = $attendees->map(function ($item) {
                $item->status = ($item->status === 'ชำระเงินแล้ว') ? 'paid' : 'pending';
                return $item;
            });

            return response()->json($attendees, 200);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    // =================================================================
    // 5. หน้า "สรุปยอดขาย" (Sales Analytics)
    // =================================================================
    public function getSalesAnalytics(Request $request)
    {
        $orgId = auth()->id() ?? 4;
        try {
            $bookings = DB::table('bookings')
                ->join('members', 'bookings.Mem_id', '=', 'members.Mem_id')
                ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                ->join('events', 'event_date_times.Event_id', '=', 'events.Event_id')
                ->where('events.Org_id', $orgId)
                ->select(
                    'bookings.Booking_id as id',
                    'members.firstnameMB as firstname',
                    'members.lastnameMB as lastname',
                    'events.eventName as event',
                    'bookings.totalPrice as amount',
                    'bookings.created_at as date',
                    'bookings.BKStatus as status'
                )
                ->orderBy('bookings.created_at', 'desc')
                ->get();

            $today = Carbon::today();
            $thisMonth = Carbon::now()->startOfMonth();
            $successful = $bookings->where('status', 'ชำระเงินแล้ว');

            $formattedTransactions = $bookings->take(10)->map(function($item) {
                return [
                    'id' => 'ORD-' . str_pad($item->id, 3, '0', STR_PAD_LEFT),
                    'customer' => $item->firstname . ' ' . $item->lastname,
                    'event' => $item->event,
                    'amount' => number_format($item->amount, 0),
                    'date' => date('d/m/Y', strtotime($item->date)),
                    'status' => ($item->status === 'ชำระเงินแล้ว') ? 'สำเร็จ' : 'รอตรวจสอบ'
                ];
            });

            return response()->json([
                'stats' => [
                    'revenueToday' => number_format($successful->filter(fn($b) => Carbon::parse($b->date)->isSameDay($today))->sum('amount'), 0),
                    'revenueMonth' => number_format($successful->filter(fn($b) => Carbon::parse($b->date)->greaterThanOrEqualTo($thisMonth))->sum('amount'), 0),
                    'totalBills' => $successful->count(),
                    'avgPerDay' => number_format($successful->count() > 0 ? $successful->sum('amount') / 30 : 0, 0)
                ],
                'transactions' => $formattedTransactions->values()
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    // =================================================================
    // 6. หน้า "แดชบอร์ดหลัก" (Dashboard Panel)
    // =================================================================
    public function getDashboardData(Request $request)
    {
        $orgId = auth()->id() ?? 4;
        try {
            $events = Event::with('ticket_zones')->where('Org_id', $orgId)->orderBy('created_at', 'desc')->get();
            
            $successfulBookings = DB::table('bookings')
                ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                ->join('events', 'event_date_times.Event_id', '=', 'events.Event_id')
                ->where('events.Org_id', $orgId)
                ->where('bookings.BKStatus', 'ชำระเงินแล้ว');

            $totalRevenue = $successfulBookings->sum('bookings.totalPrice');
            $totalTicketsSold = $successfulBookings->sum('bookings.quantity');
            $totalTickets = $events->flatMap->ticket_zones->sum('totalSeat');

            // คำนวณสถานะต่างๆ ของ Event
            $statusActive = 0;
            $statusPending = 0;
            $statusClosed = 0;

            $formattedEvents = $events->map(function($e) use (&$statusActive, &$statusPending, &$statusClosed) {
                $sold = $e->ticket_zones->sum('totalSeat') - $e->ticket_zones->sum('remainSeat');
                $total = $e->ticket_zones->sum('totalSeat');
                
                // คำนวณรายได้ต่อ 1 อีเวนต์
                $revenue = DB::table('bookings')
                    ->join('event_date_times', 'bookings.Datetime_id', '=', 'event_date_times.Datetime_id')
                    ->where('event_date_times.Event_id', $e->Event_id)
                    ->where('bookings.BKStatus', 'ชำระเงินแล้ว')
                    ->sum('bookings.totalPrice');

                $status = $e->calculated_status;
                if (in_array($status, ['เปิดขายบัตร', 'เปิดขาย', 'On Sale', 'Selling'])) $statusActive++;
                elseif (in_array($status, ['รอขาย', 'กำลังเตรียม', 'UPCOMING'])) $statusPending++;
                else $statusClosed++;

                return [
                    'id' => $e->Event_id,
                    'name' => $e->eventName,
                    'date' => $e->rental_start ? date('d/m/Y', strtotime($e->rental_start)) : 'ไม่ระบุ',
                    'salesStatus' => $status,
                    'revenue' => (float) $revenue,
                    'ticketsSold' => (int) $sold,
                    'totalTickets' => (int) $total,
                    'progress' => $total > 0 ? round(($sold / $total) * 100) : 0
                ];
            });

            // หาอีเวนต์ที่ทำเงินสูงสุด
            $topEvent = $formattedEvents->sortByDesc('revenue')->first();

            // สมมติล่าสุดที่อนุมัติ (เอาอันใหม่สุดของวันนี้)
            $latestEvent = $events->first();
            $latestApproval = null;
            if ($latestEvent && \Carbon\Carbon::parse($latestEvent->created_at)->isToday()) {
                $latestApproval = [
                    'id' => $latestEvent->Event_id,
                    'name' => $latestEvent->eventName,
                    'isToday' => true
                ];
            }

            // พ่น JSON ให้ตรงกับที่ Frontend (Interface DashboardSummary) คาดหวัง
            return response()->json([
                'overview' => [
                    'totalRevenue' => (float) $totalRevenue,
                    'revenueTrend' => '', // ปล่อยว่างไว้ก่อน หรือใส่เปอร์เซ็นต์ถ้ามีสูตรคำนวณ
                    'totalTicketsSold' => (int) $totalTicketsSold,
                    'totalTickets' => (int) $totalTickets,
                    'statusActive' => $statusActive,
                    'statusPending' => $statusPending,
                    'statusClosed' => $statusClosed,
                ],
                'topEvent' => $topEvent,
                'latestApproval' => $latestApproval,
                'events' => $formattedEvents->values() // เรียง array ใหม่ให้สวยงาม
            ], 200);

        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    // =================================================================
    // 7. หน้า "หน้าแรกของเว็บ" (Public Homepage)
    // =================================================================
    public function getPublicEvents(Request $request)
    {
        try {
            // ดึงข้อมูลทั้งหมดที่ "อนุมัติแล้ว หรือสูงกว่านั้น"
            $allowedStatuses = ['On Sale', 'Selling', 'กำลังจะจัด', 'เปิดขายบัตร', 'เปิดขาย', 'UPCOMING', 'บัตรขายหมด', 'ปิดการขาย', 'กำลังจัด'];
            
            $query = Event::with(['hall', 'event_date_times', 'ticket_zones'])
                          ->whereIn('eventStatus', $allowedStatuses);
            

            if ($request->has('category') && $request->category !== 'all') {
                $slug = $request->category;
                $mappedVenue = '';
                
                if ($slug === 'concert-fanmeet' || $slug === 'concert' || $slug === 'fanmeet') {
                    $mappedVenue = 'อิมแพ็ค';
                } elseif ($slug === 'theater') {
                    $mappedVenue = 'รัชดาลัย';
                } elseif ($slug === 'orchestra' || $slug === 'classical') {
                    $mappedVenue = 'ศาลาดนตรีสุริยเทพ';
                }

                if ($mappedVenue !== '') {
                    $query->whereHas('hall', function($q) use ($mappedVenue) {
                        $q->where('Hall_Name', 'like', '%' . $mappedVenue . '%');
                    });
                }
            }

            if ($request->has('venue')) {
                $venueName = $request->venue;
                $query->whereHas('hall', function($q) use ($venueName) {
                    $q->where('Hall_Name', 'like', '%' . $venueName . '%');
                });
            }

            if ($request->has('search')) { 
                $query->where('eventName', 'like', '%' . $request->search . '%'); 
            }
            
            $events = $query->orderBy('created_at', 'desc')->get();

            $events->transform(function ($event) {
                $event->eventStatus = $event->calculated_status;
                return $event;
            });
            
            return response()->json($events, 200);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    // =================================================================
    // Helper: สร้างผังเมืองไทยรัชดาลัย (Seating Layout)
    // =================================================================
    private function getRachadalaiFixedSeats($zoneName)
    {
        $layout = [];
        switch ($zoneName) {
            case 'L1': $layout = ['A'=>[5,12], 'B'=>[5,12], 'C'=>[4,12], 'D'=>[3,12], 'E'=>[2,12], 'F'=>[2,12], 'G'=>[2,12], 'H'=>[2,12]]; break;
            case 'C1': $layout = ['A'=>[13,28], 'B'=>[13,28], 'C'=>[13,28], 'D'=>[13,28], 'E'=>[13,28], 'F'=>[13,28], 'G'=>[13,28], 'H'=>[13,28]]; break;
            case 'R1': $layout = ['A'=>[29,36], 'B'=>[29,37], 'C'=>[29,37], 'D'=>[29,38], 'E'=>[29,39], 'F'=>[29,39], 'G'=>[29,39], 'H'=>[29,39]]; break;
            case 'L2': foreach (range('I', 'P') as $r) $layout[$r] = [1, 12]; break;
            case 'C2': foreach (range('I', 'P') as $r) $layout[$r] = [13, 28]; break;
            case 'R2': foreach (range('I', 'P') as $r) $layout[$r] = [29, 40]; break;
            case 'L3': foreach (range('Q', 'T') as $r) $layout[$r] = [1, 12]; break;
            case 'C3': foreach (range('Q', 'T') as $r) $layout[$r] = [13, 28]; break;
            case 'R3': foreach (range('Q', 'T') as $r) $layout[$r] = [29, 40]; break;
            case 'L4': $layout = ['U'=>[1,12], 'V'=>[1,12], 'W'=>[1,12], 'X'=>[1,11], 'Y'=>[1,11], 'Z'=>[1,11]]; break;
            case 'C4': $layout = ['U'=>[13,28], 'V'=>[13,28], 'W'=>[13,28]]; break;
            case 'R4': $layout = ['U'=>[29,40], 'V'=>[30,40], 'W'=>[30,40], 'X'=>[30,40], 'Y'=>[30,40], 'Z'=>[30,40]]; break;
            case 'L5': foreach (['AA','BB','CC','DD'] as $r) $layout[$r] = [2, 12]; break;
            case 'C5': foreach (['AA','BB','CC','DD'] as $r) $layout[$r] = [13, 28]; break;
            case 'R5': $layout = ['AA'=>[29,39], 'BB'=>[29,40], 'CC'=>[29,39], 'DD'=>[29,40]]; break;
            case 'L6': foreach (['EE','FF','GG','HH','II','JJ','KK'] as $r) $layout[$r] = [2, 12]; break;
            case 'C6': foreach (['EE','FF','GG','HH','II','JJ','KK'] as $r) $layout[$r] = [13, 28]; break;
            case 'R6': $layout = ['EE'=>[29,40], 'FF'=>[29,39], 'GG'=>[29,40], 'HH'=>[29,39], 'II'=>[29,40], 'JJ'=>[29,39], 'KK'=>[29,39]]; break;
            default: return null;
        }
        $seats = [];
        foreach ($layout as $row => $range) {
            for ($i = $range[0]; $i <= $range[1]; $i++) {
                $seats[] = ['row' => (string)$row, 'num' => str_pad($i, 2, '0', STR_PAD_LEFT)];
            }
        }
        return $seats;
    }

    // =================================================================
    // Helper: แปลงไฟล์ Base64 กลับเป็นรูปภาพแล้วเซฟลง Storage
    // =================================================================
    private function saveBase64Image($base64Image, $folder)
    {
        try {
            $image_parts = explode(";base64,", $base64Image);
            $image_type_aux = explode("image/", $image_parts[0]);
            $image_type = $image_type_aux[1];
            $image_base64 = base64_decode($image_parts[1]);
            
            $fileName = uniqid() . '_' . time() . '.' . $image_type;
            $filePath = $folder . '/' . $fileName;
            
            Storage::disk('public')->put($filePath, $image_base64);
            
            return $filePath;
        } catch (\Exception $e) {
            Log::error("Base64 Image Save Error: " . $e->getMessage());
            return null;
        }
    }

    // =================================================================
    // 8. ฟังก์ชัน "อัปเดตอีเวนต์" (Update Event)
    // =================================================================
    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            // ใช้ where('Event_id', $id) แทน find() เพื่อให้ชัวร์ว่าอ้างอิง Primary Key ถูกต้อง
           $event = Event::where('Event_id', $id)->first();

            if (!$event) {
                return response()->json(['message' => 'ไม่พบข้อมูลอีเวนต์'], 404);
            }

            // ✅ ดักเช็คสิทธิ์: ถ้าคนที่ Login ไม่ใช่เจ้าของงาน ให้เด้งออกทันที (HTTP 403 Forbidden)
            if ($event->Org_id !== auth()->id()) {
                return response()->json(['message' => 'ไม่มีสิทธิ์แก้ไขอีเวนต์นี้'], 403);
            }
            
            // 1. อัปเดตข้อมูลหลักของงาน
            if ($request->has('eventName')) {
                $event->eventName = $request->eventName;
            }

            if ($request->has('eventDescription')) {
                // สำคัญ: ป้องกันไม่ให้ผังที่นั่ง (GRID_DATA) หายไปตอนอัปเดต Description
                $oldDesc = $event->eventDescription;
                $gridData = '';
                if (str_contains($oldDesc, '||GRID_DATA||')) {
                    $parts = explode('||GRID_DATA||', $oldDesc);
                    $gridData = '||GRID_DATA||' . ($parts[1] ?? '');
                }
                $event->eventDescription = $request->eventDescription . $gridData;
            }

            if ($request->has('eventStatus')) {
                $event->eventStatus = $request->eventStatus;
            }

            // จัดการอัปเดตโปสเตอร์
            if ($request->has('posterImage') && str_starts_with($request->posterImage, 'data:image')) {
                $event->bannerImage = $this->saveBase64Image($request->posterImage, 'events');
            }

            $event->save();

            // 2. อัปเดตรอบการแสดง (ถ้ามีการส่งข้อมูลมา)
            if ($request->has('show_rounds')) {
                foreach ($request->show_rounds as $round) {
                    EventDateTime::where('Event_id', $id)
                        ->where('roundNumber', $round['roundNumber'])
                        ->update([
                            'startDT' => $round['startDT'],
                            'endDT' => $round['endDT'],
                            'Sale_startDT' => $round['saleStart'],
                            'Sale_endDT' => $round['saleEnd'] ?? null
                        ]);
                }
            }

            // 3. อัปเดตราคาตั๋วในแต่ละโซน
            if ($request->has('tickets')) {
                foreach ($request->tickets as $t) {
                    $zoneName = strtoupper(trim($t['zoneName']));
                    
                    TicketZone::where('Event_id', $id)
                        ->where('zoneName', $zoneName)
                        ->update([
                            // อัปเดตแค่ราคา (priceperTick) ไปก่อน 
                            // เพื่อความปลอดภัยของข้อมูลที่นั่ง (Seat) ที่อาจจะถูกจองไปแล้ว
                            'priceperTick' => $t['price']
                        ]);
                }
            }

            DB::commit();
            return response()->json([
                'message' => 'บันทึกการแก้ไขเรียบร้อยแล้ว!',
                'event' => $event
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Update Event Error: " . $e->getMessage());
            return response()->json(['message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()], 500);
        }
    }
}