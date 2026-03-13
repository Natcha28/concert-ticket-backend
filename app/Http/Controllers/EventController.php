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

        $now = Carbon::now();

        $events->transform(function ($event) use ($now) {
            // 🚨 ถ้าสถานะดั้งเดิมคือ "กำลังเตรียม" หรือ "ยกเลิกงาน" ให้ข้ามการคำนวณเวลาไปเลย
            if (!in_array($event->eventStatus, ['กำลังเตรียม', 'ยกเลิกงาน'])) {
                
                // คำนวณสถานะแบบ Real-time เฉพาะงานที่แอดมินอนุมัติแล้ว
                $firstRound = $event->event_date_times->sortBy('Sale_startDT')->first();
                if ($firstRound && $firstRound->Sale_startDT) {
                    $saleStart = Carbon::parse($firstRound->Sale_startDT);
                    $saleEnd = $firstRound->Sale_endDT ? Carbon::parse($firstRound->Sale_endDT) : null;

                    if ($now->lessThan($saleStart)) {
                        $event->eventStatus = 'กำลังจะจัด';
                    } elseif ($now->greaterThanOrEqualTo($saleStart) && (!$saleEnd || $now->lessThanOrEqualTo($saleEnd))) {
                        $event->eventStatus = 'เปิดขาย';
                    } elseif ($saleEnd && $now->greaterThan($saleEnd)) {
                        $event->eventStatus = 'ปิดการขาย';
                    }
                }

                // เช็คบัตรหมด
                $totalSeats = $event->ticket_zones->sum('totalSeat');
                $remainSeats = $event->ticket_zones->sum('remainSeat');
                if ($totalSeats > 0 && $remainSeats == 0) {
                    $event->eventStatus = 'บัตรขายหมด';
                }
            }

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
                'status'   => $event->eventStatus, // จะโชว์ "กำลังเตรียม" ถูกต้องแล้ว
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
                    'bookings.BKStatus as status' 
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

            $totalSales = $successfulBookings->sum('totalPrice');
            $ticketsSold = $successfulBookings->sum('quantity');
            $totalCapacity = $events->flatMap->ticket_zones->sum('totalSeat');

            $formattedEvents = $events->map(function($e) {
                $sold = $e->ticket_zones->sum('totalSeat') - $e->ticket_zones->sum('remainSeat');
                $total = $e->ticket_zones->sum('totalSeat');
                return [
                    'id' => $e->Event_id,
                    'name' => $e->eventName,
                    'date' => $e->rental_start ? date('d/m/Y', strtotime($e->rental_start)) : 'ไม่ระบุ',
                    'status' => $e->eventStatus,
                    'progress' => $total > 0 ? round(($sold / $total) * 100) : 0
                ];
            });

            return response()->json([
                'stats' => [
                    'totalSales' => number_format($totalSales, 0),
                    'ticketsSold' => number_format($ticketsSold, 0),
                    'ticketProgressText' => $totalCapacity > 0 ? round(($ticketsSold / $totalCapacity) * 100) . "% of total" : "0% of total",
                    'activeEvents' => $events->whereIn('eventStatus', ['เปิดขายบัตร', 'กำลังเตรียม', 'On Sale', 'กำลังจะจัด'])->count(),
                ],
                'events' => $formattedEvents
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
            // 🚨 แก้ตรงนี้: เอาคำว่า 'กำลังเตรียม' ออกจาก Array เพื่อไม่ให้ลูกค้าเห็นงานที่ยังไม่อนุมัติ
            $allowedStatuses = ['On Sale', 'Selling', 'กำลังจะจัด', 'เปิดขายบัตร', 'เปิดขาย', 'UPCOMING', 'บัตรขายหมด'];
            
            $query = Event::with(['hall', 'event_date_times', 'ticket_zones'])
                          ->whereIn('eventStatus', $allowedStatuses);
            

            if ($request->has('category') && $request->category !== 'all') {
                $slug = $request->category;
                $mappedVenue = '';
                
                // ดักจับคำเพื่อแปลงเป็นสถานที่ (อ้างอิงจาก Navbar ของคุณ)
                if ($slug === 'concert-fanmeet' || $slug === 'concert' || $slug === 'fanmeet') {
                    $mappedVenue = 'อิมแพ็ค';
                } elseif ($slug === 'theater') {
                    $mappedVenue = 'รัชดาลัย';
                } elseif ($slug === 'orchestra' || $slug === 'classical') {
                    $mappedVenue = 'ศาลาดนตรีสุริยเทพ';
                }

                // ถ้าแปลคำสำเร็จ ให้สั่ง Database ไปค้นหาจากตาราง halls
                if ($mappedVenue !== '') {
                    $query->whereHas('hall', function($q) use ($mappedVenue) {
                        $q->where('Hall_Name', 'like', '%' . $mappedVenue . '%');
                    });
                }
            }

            // 🌟 2. กรองตามสถานที่ (ถ้ามีการค้นหา venue มาตรงๆ)
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

            $now = Carbon::now();

            $events->transform(function ($event) use ($now) {
                // คำนวณสถานะเวลา
                $firstRound = $event->event_date_times->sortBy('Sale_startDT')->first();

                if ($firstRound && $firstRound->Sale_startDT) {
                    $saleStart = Carbon::parse($firstRound->Sale_startDT);
                    $saleEnd = $firstRound->Sale_endDT ? Carbon::parse($firstRound->Sale_endDT) : null;

                    if ($now->lessThan($saleStart)) {
                        $event->eventStatus = 'กำลังจะจัด';
                    } elseif ($now->greaterThanOrEqualTo($saleStart) && (!$saleEnd || $now->lessThanOrEqualTo($saleEnd))) {
                        $event->eventStatus = 'เปิดขาย';
                    } elseif ($saleEnd && $now->greaterThan($saleEnd)) {
                        $event->eventStatus = 'ปิดการขาย';
                    }
                }
                
                $totalCapacity = $event->ticket_zones->sum('totalSeat');
                $remainCapacity = $event->ticket_zones->sum('remainSeat');
                if ($totalCapacity > 0 && $remainCapacity == 0) {
                    $event->eventStatus = 'บัตรขายหมด';
                }

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
    
}