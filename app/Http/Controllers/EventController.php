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
    // Helper ใหม่: ตัวดักสถานะ ห้ามเปิดขายถ้า DB ยังไม่อนุมัติ 
    // =================================================================
    private function getRealStatus($event)
    {
        $rawStatus = $event->eventStatus;
        $notReadyStatuses = ['กำลังเตรียม', 'รอชำระเงิน', 'รออนุมัติ', 'รอแก้ไข', 'cancelled', 'ยกเลิก', 'เสร็จสิ้น'];
        
        // ถ้ายึดตาม DB แล้วยังไม่พร้อมขาย ให้ส่งสถานะ DB กลับไปเลย ห้ามใช้เวลามาออโต้เปลี่ยน
        if (in_array($rawStatus, $notReadyStatuses)) {
            return $rawStatus; 
        }
        
        // --- เริ่มแก้ไข: เช็คเวลาปัจจุบันเทียบกับเวลาเปิด/ปิดขายอัตโนมัติ ---
        $dateTimes = $event->event_date_times;
        if ($dateTimes && $dateTimes->isNotEmpty()) {
            $now = Carbon::now();
            $minStart = $dateTimes->min('Sale_startDT');
            
            if ($minStart) {
                $saleStart = Carbon::parse($minStart);
                $maxEnd = $dateTimes->max('Sale_endDT');
                $saleEnd = $maxEnd ? Carbon::parse($maxEnd) : null;

                if ($now->isBefore($saleStart)) {
                    return 'กำลังจะจัด'; // ยังไม่ถึงเวลาขาย
                } elseif ($saleEnd && $now->isAfter($saleEnd)) {
                    return 'ปิดการขาย'; // เลยเวลาปิดขายแล้ว
                } else {
                    return 'เปิดขาย'; // ถึงเวลาแล้ว ให้สถานะเป็นเปิดขาย
                }
            }
        }
        // --- จบการแก้ไข ---
        
        // ถ้าผ่านการอนุมัติแล้ว ค่อยปล่อยให้คำนวณเปิด/ปิดอัตโนมัติตามเวลา
        return $event->calculated_status ?? $rawStatus;
    }

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
                'status'   => $this->getRealStatus($event), 
                'sold'     => $soldSeats,
                'capacity' => $totalSeats,
                'image'    => $imageUrl,
                // ✅ แก้ไขตรงนี้บรรทัดเดียว! เปลี่ยนให้ใช้ Helper เดียวกันกับ Detail
                'eventStatus' => $this->getRealStatus($event) 
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

        // ✅ อัปเดตข้อมูลสถานะให้ถูกต้องก่อนส่งกลับไปที่หน้าบ้าน
        $event->eventStatus = $this->getRealStatus($event);

        // 🚨 เพิ่มบล็อกนี้: เช็คและตัดข้อความส่วน ||GRID_DATA|| ทิ้ง 🚨
        if (str_contains($event->eventDescription, '||GRID_DATA||')) {
            $parts = explode('||GRID_DATA||', $event->eventDescription);
            $event->eventDescription = trim($parts[0]); 
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
            $event->Org_id = auth()->id() ; 
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
                    
                    // 🌟 ผสานผังทั้งของ รัชดาลัย + (อิมแพ็ค, ศาลาดนตรีสุริยเทพ ม.รังสิต)
                    $fixedSeats = $this->getRachadalaiFixedSeats($zoneName) ?? $this->getDynamicLayoutSeats($zoneName);
                    
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

                // ✅ เปลี่ยนมาใช้ Helper เพื่อเช็คสถานะที่ถูกต้อง
                $status = $this->getRealStatus($e);
                if (in_array($status, ['เปิดขายบัตร', 'เปิดขาย', 'On Sale', 'Selling'])) $statusActive++;
                elseif (in_array($status, ['รอขาย', 'กำลังเตรียม', 'UPCOMING', 'รอชำระเงิน', 'รออนุมัติ', 'รอแก้ไข', 'กำลังจะจัด'])) $statusPending++;
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
                    'revenueTrend' => '', 
                    'totalTicketsSold' => (int) $totalTicketsSold,
                    'totalTickets' => (int) $totalTickets,
                    'statusActive' => $statusActive,
                    'statusPending' => $statusPending,
                    'statusClosed' => $statusClosed,
                ],
                'topEvent' => $topEvent,
                'latestApproval' => $latestApproval,
                'events' => $formattedEvents->values() 
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
            $allowedStatuses = ['On Sale', 'Selling', 'กำลังจะจัด', 'เปิดขายบัตร', 'เปิดขาย', 'UPCOMING', 'บัตรขายหมด', 'ปิดการขาย', 'กำลังจัด', 'approved', 'Approved'];
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
                // ✅ เปลี่ยนมาใช้ Helper เพื่อเช็คสถานะที่ถูกต้อง
                $event->eventStatus = $this->getRealStatus($event);
                return $event;
            });
            
            return response()->json($events, 200);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    // =================================================================
    // Helper: สร้างผัง อิมแพ็ค อารีน่า และ ศาลาดนตรีสุริยเทพ ม.รังสิต (ใหม่)
    // =================================================================
    private function getDynamicLayoutSeats($zoneName)
    {
        $name = strtoupper(trim($zoneName));
        $maxCol = 20;
        $startNum = 1;
        $matrix = [];
        $rowLetters = range('A', 'Z');
        $isFixedLayout = false;

        if (in_array($name, ["A1", "A2", "A3", "A4"])) {
            $isFixedLayout = true; $rowLetters = range('A', 'T'); $maxCol = 25; $matrix = [['l'=>0, 'r'=>0]];
        } else if ($name === "A5") {
            $isFixedLayout = true; $rowLetters = range('A', 'T'); $maxCol = 25;
            for($i=0;$i<15;$i++) $matrix[] = ['l'=>0, 'r'=>0];
            array_push($matrix, ['l'=>2,'r'=>0], ['l'=>4,'r'=>0], ['l'=>6,'r'=>0], ['l'=>8,'r'=>0], ['l'=>10,'r'=>0]);
        } else if ($name === "A6") {
            $isFixedLayout = true; $rowLetters = range('A', 'T'); $maxCol = 25;
            for($i=0;$i<15;$i++) $matrix[] = ['l'=>0, 'r'=>0];
            array_push($matrix, ['l'=>0,'r'=>2], ['l'=>0,'r'=>4], ['l'=>0,'r'=>6], ['l'=>0,'r'=>8], ['l'=>0,'r'=>10]);
        } else if ($name === "A7") {
            $isFixedLayout = true; $rowLetters = range('A', 'J'); $maxCol = 48;
            for($i=0;$i<5;$i++) $matrix[] = ['l'=>0, 'r'=>0];
            array_push($matrix, ['l'=>2,'r'=>2], ['l'=>4,'r'=>4], ['l'=>6,'r'=>6], ['l'=>8,'r'=>8], ['l'=>10,'r'=>10]);
        } else if (in_array($name, ["SB", "SC", "SD", "SL", "SM", "SN"])) {
            $isFixedLayout = true; $rowLetters = range('A', 'H'); $maxCol = 20; $matrix = [['l'=>0, 'r'=>0]];
        } else if (in_array($name, ["SE", "SK"])) {
            $isFixedLayout = true; $rowLetters = range('A', 'H'); $maxCol = 27;
            $matrix = [['l'=>0,'r'=>6], ['l'=>0,'r'=>5], ['l'=>0,'r'=>4], ['l'=>0,'r'=>4], ['l'=>0,'r'=>3], ['l'=>0,'r'=>2], ['l'=>0,'r'=>1], ['l'=>0,'r'=>0]];
        } else if (in_array($name, ["SF", "SJ"])) {
            $isFixedLayout = true; $rowLetters = range('A', 'H'); $maxCol = 24;
            $matrix = [['l'=>0,'r'=>8], ['l'=>0,'r'=>7], ['l'=>0,'r'=>6], ['l'=>0,'r'=>5], ['l'=>0,'r'=>3], ['l'=>0,'r'=>2], ['l'=>0,'r'=>1], ['l'=>0,'r'=>0]];
        } else if (in_array($name, ["SG", "SI"])) {
            $isFixedLayout = true; $rowLetters = range('A', 'H'); $maxCol = 20;
            $matrix = [['l'=>0,'r'=>6], ['l'=>0,'r'=>5], ['l'=>0,'r'=>4], ['l'=>0,'r'=>3], ['l'=>0,'r'=>3], ['l'=>0,'r'=>2], ['l'=>0,'r'=>1], ['l'=>0,'r'=>0]];
        } else if ($name === "SH") {
            $isFixedLayout = true; $rowLetters = range('A', 'H'); $maxCol = 19; $matrix = [['l'=>0, 'r'=>0]];
        } else if (in_array($name, ["B", "T"])) {
            $isFixedLayout = true; $rowLetters = range('A', 'R'); $maxCol = 10; $isT = ($name === "T");
            $matrix = [
                ['l'=>$isT?0:5, 'r'=>$isT?5:0], ['l'=>$isT?0:3, 'r'=>$isT?3:0], ['l'=>$isT?0:3, 'r'=>$isT?3:0], ['l'=>$isT?0:3, 'r'=>$isT?3:0],
                ['l'=>$isT?0:3, 'r'=>$isT?3:0], ['l'=>$isT?0:3, 'r'=>$isT?3:0], ['l'=>$isT?0:4, 'r'=>$isT?4:0], ['l'=>$isT?0:5, 'r'=>$isT?5:0],
                ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0]
            ];
        } else if (in_array($name, ["C", "D", "S", "R"])) {
            $isFixedLayout = true; $rowLetters = ["AA", "A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q"]; $maxCol = 20;
            $matrix = [
                ['l'=>4,'r'=>5], ['l'=>3,'r'=>4], ['l'=>3,'r'=>4], ['l'=>3,'r'=>4], ['l'=>3,'r'=>4], ['l'=>3,'r'=>4], ['l'=>4,'r'=>5], ['l'=>5,'r'=>5],
                ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0]
            ];
        } else if (in_array($name, ["E", "Q"])) {
            $isFixedLayout = true; $rowLetters = ["AA", "A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q"]; $maxCol = 23;
            $matrix = [
                ['l'=>5,'r'=>9], ['l'=>4,'r'=>7], ['l'=>4,'r'=>6], ['l'=>4,'r'=>6], ['l'=>4,'r'=>6], ['l'=>4,'r'=>5], ['l'=>5,'r'=>4], ['l'=>5,'r'=>5],
                ['l'=>0,'r'=>5], ['l'=>0,'r'=>5], ['l'=>0,'r'=>5], ['l'=>0,'r'=>4], ['l'=>0,'r'=>3], ['l'=>0,'r'=>2], ['l'=>0,'r'=>1], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0], ['l'=>0,'r'=>0]
            ];
        } else if (in_array($name, ["F", "P"])) {
            $isFixedLayout = true; $rowLetters = range('A', 'U'); $maxCol = 19; $isP = ($name === "P");
            $matrix = [
                ['l'=>$isP?3:12, 'r'=>$isP?12:3], ['l'=>$isP?3:11, 'r'=>$isP?11:3], ['l'=>$isP?3:11, 'r'=>$isP?11:3], ['l'=>$isP?3:10, 'r'=>$isP?10:3],
                ['l'=>$isP?3:10, 'r'=>$isP?10:3], ['l'=>$isP?3:9,  'r'=>$isP?9:3],  ['l'=>$isP?3:9,  'r'=>$isP?9:3],  ['l'=>$isP?0:5,  'r'=>$isP?5:0],
                ['l'=>$isP?0:5,  'r'=>$isP?5:0],  ['l'=>$isP?0:4,  'r'=>$isP?4:0],  ['l'=>$isP?0:4,  'r'=>$isP?4:0],  ['l'=>$isP?0:4,  'r'=>$isP?4:0],
                ['l'=>$isP?0:3,  'r'=>$isP?3:0],  ['l'=>$isP?0:2,  'r'=>$isP?2:0],  ['l'=>$isP?0:1,  'r'=>$isP?1:0],  ['l'=>$isP?0:1,  'r'=>$isP?1:0],
                ['l'=>0, 'r'=>0], ['l'=>$isP?0:1,  'r'=>$isP?1:0], ['l'=>$isP?0:2,  'r'=>$isP?2:0], ['l'=>$isP?0:3,  'r'=>$isP?3:0], ['l'=>$isP?8:5,  'r'=>$isP?5:8]
            ];
        } else if (in_array($name, ["G", "O"])) {
            $isFixedLayout = true; $rowLetters = range('A', 'T'); $maxCol = 20; $isO = ($name === "O");
            $matrix = [
                ['l'=>$isO?0:17, 'r'=>$isO?17:0], ['l'=>$isO?0:16, 'r'=>$isO?16:0], ['l'=>$isO?0:16, 'r'=>$isO?16:0], ['l'=>$isO?0:15, 'r'=>$isO?15:0],
                ['l'=>$isO?0:15, 'r'=>$isO?15:0], ['l'=>$isO?0:15, 'r'=>$isO?15:0], ['l'=>$isO?0:15, 'r'=>$isO?15:0], ['l'=>$isO?0:5,  'r'=>$isO?5:0],
                ['l'=>$isO?0:5,  'r'=>$isO?5:0],  ['l'=>$isO?0:4,  'r'=>$isO?4:0],  ['l'=>$isO?0:4,  'r'=>$isO?4:0],  ['l'=>$isO?0:4,  'r'=>$isO?4:0],
                ['l'=>$isO?0:3,  'r'=>$isO?3:0],  ['l'=>$isO?0:3,  'r'=>$isO?3:0],  ['l'=>$isO?0:2,  'r'=>$isO?2:0],  ['l'=>$isO?0:2,  'r'=>$isO?2:0],
                ['l'=>$isO?0:1,  'r'=>$isO?1:0],  ['l'=>$isO?0:1,  'r'=>$isO?1:0],  ['l'=>$isO?0:1,  'r'=>$isO?1:0],  ['l'=>0, 'r'=>0]
            ];
        } else if (in_array($name, ["H", "N"])) {
            $isFixedLayout = true; $rowLetters = range('A', 'U'); $maxCol = 19; $isN = ($name === "N");
            $matrix = [
                ['l'=>$isN?16:0, 'r'=>$isN?0:16], ['l'=>$isN?15:0, 'r'=>$isN?0:15], ['l'=>$isN?15:0, 'r'=>$isN?0:15], ['l'=>$isN?14:0, 'r'=>$isN?0:14],
                ['l'=>$isN?14:0, 'r'=>$isN?0:14], ['l'=>$isN?14:0, 'r'=>$isN?0:14], ['l'=>$isN?14:0, 'r'=>$isN?0:14], ['l'=>$isN?6:0,  'r'=>$isN?0:6],
                ['l'=>$isN?6:0,  'r'=>$isN?0:6],  ['l'=>$isN?5:0,  'r'=>$isN?0:5],  ['l'=>$isN?5:0,  'r'=>$isN?0:5],  ['l'=>$isN?4:0,  'r'=>$isN?0:4],
                ['l'=>$isN?3:0,  'r'=>$isN?0:3],  ['l'=>$isN?3:0,  'r'=>$isN?0:3],  ['l'=>$isN?2:0,  'r'=>$isN?0:2],  ['l'=>$isN?2:0,  'r'=>$isN?0:2],
                ['l'=>$isN?1:0,  'r'=>$isN?0:1],  ['l'=>$isN?1:0,  'r'=>$isN?0:1],  ['l'=>$isN?1:0,  'r'=>$isN?0:1],  ['l'=>0, 'r'=>0], ['l'=>0, 'r'=>0]
            ];
        } else if (in_array($name, ["I", "M"])) {
            $isFixedLayout = true; $rowLetters = range('A', 'U'); $maxCol = 20; $isM = ($name === "M");
            $matrix = [
                ['l'=>$isM?0:17, 'r'=>$isM?17:0], ['l'=>$isM?0:16, 'r'=>$isM?16:0], ['l'=>$isM?0:16, 'r'=>$isM?16:0], ['l'=>$isM?0:15, 'r'=>$isM?15:0],
                ['l'=>$isM?0:15, 'r'=>$isM?15:0], ['l'=>$isM?0:15, 'r'=>$isM?15:0], ['l'=>$isM?0:15, 'r'=>$isM?15:0], ['l'=>$isM?0:5,  'r'=>$isM?5:0],
                ['l'=>$isM?0:5,  'r'=>$isM?5:0],  ['l'=>$isM?0:4,  'r'=>$isM?4:0],  ['l'=>$isM?0:4,  'r'=>$isM?4:0],  ['l'=>$isM?0:4,  'r'=>$isM?4:0],
                ['l'=>$isM?0:3,  'r'=>$isM?3:0],  ['l'=>$isM?0:3,  'r'=>$isM?3:0],  ['l'=>$isM?0:2,  'r'=>$isM?2:0],  ['l'=>$isM?0:2,  'r'=>$isM?2:0],
                ['l'=>$isM?0:1,  'r'=>$isM?1:0],  ['l'=>$isM?0:1,  'r'=>$isM?1:0],  ['l'=>$isM?0:1,  'r'=>$isM?1:0],  ['l'=>0, 'r'=>0], ['l'=>0, 'r'=>0]
            ];
        } else if (in_array($name, ["J", "L"])) {
            $isFixedLayout = true; $rowLetters = range('A', 'W'); $maxCol = 20; $isL = ($name === "L");
            $matrix = [
                ['l'=>$isL?17:0, 'r'=>$isL?0:17], ['l'=>$isL?17:0, 'r'=>$isL?0:17], ['l'=>$isL?16:0, 'r'=>$isL?0:16], ['l'=>$isL?16:0, 'r'=>$isL?0:16],
                ['l'=>$isL?16:0, 'r'=>$isL?0:16], ['l'=>$isL?5:0,  'r'=>$isL?0:5],  ['l'=>$isL?5:0,  'r'=>$isL?0:5],  ['l'=>$isL?5:0,  'r'=>$isL?0:5],
                ['l'=>$isL?4:0,  'r'=>$isL?0:4],  ['l'=>$isL?4:0,  'r'=>$isL?0:4],  ['l'=>$isL?3:0,  'r'=>$isL?0:3],  ['l'=>$isL?3:0,  'r'=>$isL?0:3],
                ['l'=>$isL?2:0,  'r'=>$isL?0:2],  ['l'=>$isL?2:0,  'r'=>$isL?0:2],  ['l'=>$isL?1:0,  'r'=>$isL?0:1],  ['l'=>$isL?1:0,  'r'=>$isL?0:1],
                ['l'=>$isL?1:0,  'r'=>$isL?0:1],  ['l'=>0, 'r'=>0], ['l'=>$isL?11:0, 'r'=>$isL?0:11], ['l'=>$isL?7:0,  'r'=>$isL?0:7],
                ['l'=>$isL?7:0,  'r'=>$isL?0:7],  ['l'=>$isL?7:0,  'r'=>$isL?0:7],  ['l'=>$isL?6:0,  'r'=>$isL?0:6]
            ];
        } else if ($name === "K") {
            $isFixedLayout = true; $rowLetters = ["AA", "A", "B", "C", "D", "E", "F", "G", "H", "I", "J", "K", "L", "M", "N", "O", "P", "Q"]; $maxCol = 14;
            $matrix = [
                ['l'=>2, 'r'=>2], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1],
                ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1], ['l'=>1, 'r'=>1],
                ['l'=>1, 'r'=>1], ['l'=>0, 'r'=>0]
            ];
        }
        
        else if (in_array($name, ["ZONE 1", "ZONE1"])) {
            $isFixedLayout = true; $rowLetters = ["C", "D", "E", "F", "G", "H", "I", "J", "K", "L"]; $maxCol = 20;
            $matrix = [
                ['l'=>2, 'r'=>6, 'gapAfter'=>8], ['l'=>1, 'r'=>6, 'gapAfter'=>9], ['l'=>2, 'r'=>6, 'gapAfter'=>8],
                ['l'=>3, 'r'=>5, 'gapAfter'=>8], ['l'=>4, 'r'=>4, 'gapAfter'=>7], ['l'=>5, 'r'=>2, 'gapAfter'=>6],
                ['l'=>5, 'r'=>2, 'gapAfter'=>6], ['l'=>12, 'r'=>1, 'gapAfter'=>0], ['l'=>5, 'r'=>3], ['l'=>5, 'r'=>3]
            ];
        } else if (in_array($name, ["ZONE 2", "ZONE2"])) {
            $isFixedLayout = true; $rowLetters = ["C", "D", "E", "F", "G", "H", "I", "J", "K", "L"]; $maxCol = 22;
            $matrix = [
                ['l'=>6, 'r'=>2, 'start'=>12], ['l'=>6, 'r'=>1, 'start'=>13], ['l'=>6, 'r'=>2, 'start'=>12],
                ['l'=>5, 'r'=>2, 'start'=>12], ['l'=>5, 'r'=>1, 'start'=>12], ['l'=>5, 'r'=>2, 'start'=>12],
                ['l'=>6, 'r'=>2, 'start'=>13], ['l'=>1, 'r'=>6, 'start'=>8],  ['l'=>6, 'r'=>0, 'start'=>13], ['l'=>6, 'r'=>1, 'start'=>13]
            ];
        } else if (in_array($name, ["ZONE 3", "ZONE3"])) {
            $isFixedLayout = true; $rowLetters = ["C", "D", "E", "F", "G", "H", "I", "J", "K", "L"]; $maxCol = 20;
            $matrix = [
                ['l'=>5, 'r'=>3, 'gapAfter'=>3, 'start'=>26], ['l'=>5, 'r'=>2, 'gapAfter'=>3, 'start'=>28], ['l'=>5, 'r'=>3, 'gapAfter'=>3, 'start'=>26],
                ['l'=>4, 'r'=>3, 'gapAfter'=>4, 'start'=>27], ['l'=>4, 'r'=>4, 'gapAfter'=>4, 'start'=>28], ['l'=>3, 'r'=>5, 'gapAfter'=>5, 'start'=>27],
                ['l'=>2, 'r'=>5, 'gapAfter'=>6, 'start'=>27], ['l'=>1, 'r'=>11, 'gapAfter'=>7, 'start'=>23], ['l'=>2, 'r'=>6, 'start'=>29], ['l'=>3, 'r'=>5, 'start'=>28]
            ];
        } else if (in_array($name, ["ZONE 4", "ZONE4"])) {
            $isFixedLayout = true; $rowLetters = ["M", "N", "O", "P", "Q", "R", "S", "T", "U", "V"]; $maxCol = 16;
            $matrix = [
                ['l'=>3, 'r'=>2], ['l'=>2, 'r'=>3], ['l'=>2, 'r'=>2], ['l'=>2, 'r'=>2],
                ['l'=>2, 'r'=>2], ['l'=>3, 'r'=>2], ['l'=>4, 'r'=>1], ['l'=>4, 'r'=>1],
                ['l'=>4, 'r'=>1], ['l'=>4, 'r'=>1]
            ];
        } else if (in_array($name, ["ZONE 5", "ZONE5"])) {
            $isFixedLayout = true; $rowLetters = ["M", "N", "O", "P", "Q", "R", "S", "T"]; $maxCol = 20;
            $matrix = [
                ['l'=>3, 'r'=>3, 'start'=>12], ['l'=>2, 'r'=>3, 'start'=>12], ['l'=>3, 'r'=>1, 'start'=>13],
                ['l'=>3, 'r'=>2, 'start'=>13], ['l'=>3, 'r'=>1, 'start'=>13], ['l'=>2, 'r'=>3, 'start'=>12],
                ['l'=>3, 'r'=>1, 'start'=>12], ['l'=>2, 'r'=>3, 'start'=>12]
            ];
        } else if (in_array($name, ["ZONE 6", "ZONE6"])) {
            $isFixedLayout = true; $rowLetters = ["M", "N", "O", "P", "Q", "R", "S", "T", "U", "V"]; $maxCol = 16;
            $matrix = [
                ['l'=>2, 'r'=>3, 'start'=>26], ['l'=>3, 'r'=>2, 'start'=>27], ['l'=>2, 'r'=>2, 'start'=>29],
                ['l'=>2, 'r'=>2, 'start'=>28], ['l'=>2, 'r'=>2, 'start'=>29], ['l'=>3, 'r'=>2, 'start'=>27],
                ['l'=>2, 'r'=>3, 'start'=>28], ['l'=>3, 'r'=>2, 'start'=>27], ['l'=>2, 'r'=>3, 'start'=>26], ['l'=>3, 'r'=>2, 'start'=>27]
            ];
        } else if (in_array($name, ["ZONE 7", "ZONE7"])) {
            $isFixedLayout = true; $rowLetters = ["AA", "BB", "CC", "DD", "EE", "FF", "GG", "HH"]; $maxCol = 11;
            $matrix = array_fill(0, 8, ['l'=>0, 'r'=>0]);
        } else if (in_array($name, ["ZONE 8", "ZONE8"])) {
            $isFixedLayout = true; $rowLetters = ["AA", "BB", "CC", "DD", "EE", "FF", "GG", "HH"]; $maxCol = 16;
            $matrix = [
                ['l'=>0, 'r'=>0, 'start'=>12], ['l'=>1, 'r'=>0, 'start'=>12],
                ['l'=>0, 'r'=>0, 'start'=>12], ['l'=>1, 'r'=>0, 'start'=>12],
                ['l'=>0, 'r'=>0, 'start'=>12], ['l'=>1, 'r'=>0, 'start'=>12],
                ['l'=>0, 'r'=>0, 'start'=>12], ['l'=>1, 'r'=>0, 'start'=>12]
            ];
        } else if (in_array($name, ["ZONE 9", "ZONE9"])) {
            $isFixedLayout = true; $rowLetters = ["AA", "BB", "CC", "DD", "EE", "FF", "GG", "HH"]; $maxCol = 11;
            $matrix = [
                ['l'=>0, 'r'=>0, 'start'=>28], ['l'=>0, 'r'=>0, 'start'=>27], ['l'=>0, 'r'=>0, 'start'=>28], ['l'=>0, 'r'=>0, 'start'=>27],
                ['l'=>0, 'r'=>0, 'start'=>28], ['l'=>0, 'r'=>0, 'start'=>27], ['l'=>0, 'r'=>0, 'start'=>28], ['l'=>0, 'r'=>0, 'start'=>27]
            ];
        }

        if (!$isFixedLayout) return null;

        $seats = [];
        for ($rowIndex = 0; $rowIndex < count($rowLetters); $rowIndex++) {
            $baseLetter = $rowLetters[$rowIndex % count($rowLetters)];
            $cycle = floor($rowIndex / count($rowLetters));
            $rowLetter = $cycle > 0 ? $baseLetter . ($cycle + 1) : $baseLetter;

            $conf = $matrix[$rowIndex] ?? (count($matrix) > 0 ? end($matrix) : ['l'=>0, 'r'=>0]);
            
            $gapCount = isset($conf['gapAfter']) ? 1 : 0;
            $lBlanks = $conf['l'] ?? 0;
            $rBlanks = $conf['r'] ?? 0;
            
            $maxReal = $maxCol - $lBlanks - $rBlanks - $gapCount;
            $realToPlace = $maxReal;

            for ($i = 0; $i < $realToPlace; $i++) {
                $cleanName = trim($name);
                $isRightSideZone = in_array($cleanName, ["SK", "SL", "SM", "SN", "SJ", "SI", "J", "L", "K", "M", "N", "O", "P", "Q", "R", "S", "T"]);
                $isRangsitZone = strpos($cleanName, "ZONE") !== false;

                if ($isRightSideZone) {
                    $seatNum = $realToPlace - $i;
                } else if ($isRangsitZone && isset($conf['start'])) {
                    $seatNum = $conf['start'] + $i;
                } else {
                    $seatNum = $startNum + $lBlanks + $i;
                }

                $seats[] = [
                    'row' => (string)$rowLetter,
                    'num' => str_pad($seatNum, 2, '0', STR_PAD_LEFT)
                ];
            }
        }
        return $seats;
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
            $event = Event::where('Event_id', $id)->first();

            if (!$event) {
                return response()->json(['message' => 'ไม่พบข้อมูลอีเวนต์'], 404);
            }
            
            // อัปเดตข้อมูลหลัก 
            if ($request->has('title')) {
                $event->eventName = $request->title;
            }
            if ($request->has('approvalStatus')) {
                $event->eventStatus = $request->approvalStatus;
            }
            if ($request->has('date') && $request->date) {
                $event->rental_start = $request->date;
            }
            if ($request->has('endDate') && $request->endDate) {
                $event->rental_end = $request->endDate;
            }

            // จัดการ Description (ล้าง ||GRID_DATA|| ขยะ)
            if ($request->has('description')) {
                $newDesc = (string) $request->description;
                if (str_contains($newDesc, '||GRID_DATA||')) {
                    $newDesc = explode('||GRID_DATA||', $newDesc)[0];
                }

                $oldDesc = (string) $event->eventDescription;
                $gridData = '';
                if (str_contains($oldDesc, '||GRID_DATA||')) {
                    $parts = explode('||GRID_DATA||', $oldDesc);
                    $gridData = '||GRID_DATA||' . ($parts[1] ?? '{}');
                }
                
                $event->eventDescription = mb_substr(trim($newDesc) . $gridData, 0, 4900);
            }

            // จัดการรูปภาพ
            if ($request->has('image')) {
                $img = $request->image;
                if (is_string($img) && str_starts_with($img, 'data:image')) {
                    $savedPath = $this->saveBase64Image($img, 'events');
                    if ($savedPath) $event->bannerImage = $savedPath;
                } elseif (is_string($img) && $img !== '') {
                    $event->bannerImage = $img;
                }
            }
            
            $event->save();

            // ✅ อัปเดตตารางรอบการแสดงด้วย Eloquent โดยตรง
            if ($request->has('date') && $request->date) {
                $time = $request->time ?? '00:00:00';
                if (strlen($time) === 5) $time .= ':00'; 
                $endDateStr = $request->endDate ?: $request->date;

                // ค้นหาแถวเวลาของอีเวนต์นี้
                $eventTime = EventDateTime::where('Event_id', $id)->first();
                
                if ($eventTime) {
                    $eventTime->startDT = $request->date . ' ' . $time;
                    $eventTime->endDT = $endDateStr . ' ' . $time;
                    
                    if ($request->has('saleStartDate') && $request->saleStartDate) {
                        try {
                            $eventTime->Sale_startDT = \Carbon\Carbon::parse($request->saleStartDate)->format('Y-m-d H:i:s');
                        } catch (\Throwable $e) {}
                    }
                    
                    // ✅ เพิ่มบล็อกนี้สำหรับวันปิดขายบัตร
                    if ($request->has('saleEndDate') && $request->saleEndDate) {
                        try {
                            $eventTime->Sale_endDT = \Carbon\Carbon::parse($request->saleEndDate)->format('Y-m-d H:i:s');
                        } catch (\Throwable $e) {}
                    }
                    
                    $eventTime->save();
                } else {
                    // ถ้าหลุดไปจริงๆ ให้สร้างใหม่เลย
                    EventDateTime::create([
                        'Event_id' => $id,
                        'roundNumber' => 1,
                        'startDT' => $request->date . ' ' . $time,
                        'endDT' => $endDateStr . ' ' . $time,
                        'Sale_startDT' => $request->has('saleStartDate') ? \Carbon\Carbon::parse($request->saleStartDate)->format('Y-m-d H:i:s') : now(),
                        // ✅ เพิ่มบรรทัดนี้ด้วย
                        'Sale_endDT' => ($request->has('saleEndDate') && $request->saleEndDate) ? \Carbon\Carbon::parse($request->saleEndDate)->format('Y-m-d H:i:s') : null,
                    ]);
                }
            }

            // อัปเดตโซนและราคา
            if ($request->has('tickets') && is_array($request->tickets)) {
                foreach ($request->tickets as $t) {
                    $zoneName = strtoupper(trim($t['zone'] ?? ''));
                    if ($zoneName !== '') {
                        $zone = TicketZone::where('Event_id', $id)->where('zoneName', $zoneName)->first();
                        if ($zone) {
                            $zone->priceperTick = $t['price'] ?? 0;
                            $zone->save();
                        }
                    }
                }
            }

            DB::commit();
            return response()->json(['message' => 'บันทึกการแก้ไขเรียบร้อยแล้ว!'], 200);

        } catch (\Throwable $e) { 
            DB::rollBack();
            Log::error("Update Event Error: " . $e->getMessage());
            return response()->json(['message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()], 500);
        }
    }
    // =================================================================
    // ฟังก์ชันจำลองการชำระเงิน (อัปเดตใหม่ ใช้ DB::table แก้ปัญหา Error 500)
    // =================================================================
    public function simulatePayment(Request $request, $eventId)
    {
        try {
            // 1. เช็คว่ามีอีเวนต์นี้อยู่จริงไหม
            $event = \Illuminate\Support\Facades\DB::table('events')->where('Event_id', $eventId)->first();
            if (!$event) {
                return response()->json(['success' => false, 'message' => 'ไม่พบข้อมูลอีเวนต์'], 404);
            }

            // 2. ใช้ DB::table ตรงๆ เพื่อหลีกเลี่ยงปัญหา Model $fillable
            $existingPayment = \Illuminate\Support\Facades\DB::table('event_payments')->where('Event_id', $eventId)->first();

            if ($existingPayment) {
                // ถ้ามีประวัติอยู่แล้ว ให้อัปเดตเวลาและสถานะ
                \Illuminate\Support\Facades\DB::table('event_payments')->where('Event_id', $eventId)->update([
                    'PayDate' => now(),
                    'payStatus' => 'ชำระเรียบร้อยแล้ว',
                    'updated_at' => now()
                ]);
            } else {
                // ถ้ายังไม่มีประวัติ ให้สร้างบรรทัดใหม่
                \Illuminate\Support\Facades\DB::table('event_payments')->insert([
                    'Event_id' => $eventId,
                    'PayDate' => now(),
                    'eventAmount' => 35000, // ค่าเช่าสมมติ
                    'payStatus' => 'ชำระเรียบร้อยแล้ว',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            // 3. ปรับสถานะงานในตาราง events ให้เป็น 'กำลังจะจัด' พร้อมเปิดขายเลย
            \Illuminate\Support\Facades\DB::table('events')->where('Event_id', $eventId)->update([
                'eventStatus' => 'กำลังจะจัด',
                'updated_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'จำลองการชำระเงินสำเร็จ เวลาถูกบันทึกเรียบร้อย'
            ], 200);

        } catch (\Exception $e) {
            // ถ้าพัง มันจะพ่นแจ้งเตือน Error จริงๆ ออกมาให้เห็น (ไม่เป็น 500 ปริศนาอีกต่อไป)
            return response()->json([
                'success' => false,
                'message' => 'Database Error: ' . $e->getMessage()
            ], 500);
        }
    }
}