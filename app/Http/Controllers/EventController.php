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
    // 1. หน้า "อีเวนต์ของฉัน" (My Events) - โชว์รายการงานทั้งหมดของผู้จัด
    // =================================================================
    public function index()
    {
        $orgId = auth()->id() ?? 4; // ใส่ fallback เป็น 4 เผื่อกรณีทดสอบแล้ว token หลุด
        $events = Event::with(['hall', 'ticket_zones', 'event_date_times']) 
                        ->where('Org_id', $orgId)
                        ->where('eventStatus', '!=', 'เสร็จสิ้น')
                        ->orderBy('created_at', 'desc')
                        ->get();

        $events->transform(function ($event) {
            $totalSeats = $event->ticket_zones->sum('totalSeat');
            $remainSeats = $event->ticket_zones->sum('remainSeat');
            $soldSeats = $totalSeats - $remainSeats;
            
            $imageUrl = null;
            if ($event->bannerImage) {
                $imageUrl = str_starts_with($event->bannerImage, 'http') ? $event->bannerImage : asset('storage/' . $event->bannerImage);
            }

            return [
                'id'       => $event->Event_id,
                'title'    => $event->eventName,
                'date'     => $event->rental_start, 
                'location' => $event->hall->Hall_Name ?? 'ไม่ระบุสถานที่',
                'status'   => $event->eventStatus, 
                'sold'     => $soldSeats,
                'capacity' => $totalSeats,
                'image'    => $imageUrl
            ];
        });
        return response()->json($events);
    }

    // =================================================================
    // 2. หน้า "ดูรายละเอียดงาน" (Event Detail) - โชว์ข้อมูลงานรายตัว
    // =================================================================
    public function show($id)
    {
        $event = Event::with(['hall', 'event_date_times', 'ticket_zones']) 
              ->where('Event_id', $id)
              ->first();
        
        if (!$event) { return response()->json(['message' => 'Not Found'], 404); }
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
            $event->bannerImage = str_starts_with($request->posterImage, 'data:image') ? $this->saveBase64Image($request->posterImage, 'events') : $request->posterImage;
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
                    $hallZone = HallZone::create([
                        'Hall_id' => $request->Hall_id,
                        'zoneName' => $t['zoneName'] ?? 'Zone ' . ($index + 1),
                        'zoneCapacity' => $t['quantity'] ?? 0
                    ]);

                    $ticketZone = TicketZone::create([
                        'Event_id' => $event->Event_id,
                        'HallZone_id' => $hallZone->HallZone_id,
                        'zoneName' => $t['zoneName'],
                        'colorZone' => '#FFFFFF',
                        'priceperTick' => $t['price'],
                        'totalSeat' => $t['quantity'],
                        'remainSeat' => $t['quantity']
                    ]);

                    // วนลูปสร้างที่นั่ง
                    $maxSeatsPerRow = 20; 
                    $currentQuantity = 0;
                    $rowIdx = 0;
                    $rowLetters = range('A', 'Z');

                    while ($currentQuantity < $t['quantity']) {
                        $rowLetter = $rowLetters[$rowIdx] ?? 'Z' . $rowIdx;
                        for ($seatNum = 1; $seatNum <= $maxSeatsPerRow && $currentQuantity < $t['quantity']; $seatNum++) {
                            Seat::create([
                                'Zone_id' => $ticketZone->Zone_id,
                                'SeatRow' => $rowLetter,
                                'SeatNo' => str_pad($seatNum, 2, '0', STR_PAD_LEFT),
                                'SeatStatus' => 'ว่าง'
                            ]);
                            $currentQuantity++;
                        }
                        $rowIdx++;
                    }
                }
            }

            DB::commit(); 
            return response()->json(['message' => 'บันทึกสำเร็จและสร้างที่นั่งเรียบร้อย', 'event_id' => $event->Event_id], 201);

        } catch (\Exception $e) {
            DB::rollBack(); 
            return response()->json(['message' => 'Server Error: ' . $e->getMessage()], 500);
        }
    }

    // =================================================================
    // 4. หน้า "รายชื่อผู้เข้าร่วม" (Customers / Attendees) 
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

            // แปลงสถานะให้ตรงกับที่ React คาดหวัง
            $attendees = $attendees->map(function ($item) {
                if ($item->status === 'ชำระเงินแล้ว') {
                    $item->status = 'paid';
                } else {
                    $item->status = 'pending';
                }
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

            // จัดรูปแบบรายการสั่งซื้อ 10 รายการล่าสุด
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
    // Helper Function: เซฟรูปภาพ Base64
    // =================================================================
    private function saveBase64Image($base64String, $folder)
    {
        if (preg_match('/^data:image\/(\w+);base64,/', $base64String, $type)) {
            $base64String = substr($base64String, strpos($base64String, ',') + 1);
            $type = strtolower($type[1]); 
            if (!in_array($type, [ 'jpg', 'jpeg', 'gif', 'png' ])) { return null; }
            $base64String = base64_decode($base64String);
            $fileName = Str::random(10) . '.' . $type;
            $filePath = $folder . '/' . $fileName;
            Storage::disk('public')->put($filePath, $base64String);
            return $filePath;
        }
        return null;
    }
    // =================================================================
    // 7. หน้า "หน้าแรกของเว็บ" (Public Homepage) - โชว์งานทั้งหมดให้ลูกค้าดู
    // =================================================================
    public function getPublicEvents(Request $request)
    {
        try {
            // ดึงเฉพาะงานที่มีสถานะอนุญาตให้คนทั่วไปเห็นได้
            $allowedStatuses = ['On Sale', 'Selling', 'กำลังจะจัด', 'เปิดขายบัตร', 'UPCOMING', 'กำลังเตรียม', 'บัตรขายหมด'];
            
            $query = Event::with(['hall', 'event_date_times', 'ticket_zones'])
                          ->whereIn('eventStatus', $allowedStatuses);
            
            // ถ้าระบบมีการพิมพ์ค้นหาชื่อคอนเสิร์ต
            if ($request->has('search')) { 
                $query->where('eventName', 'like', '%' . $request->search . '%'); 
            }
            
            return response()->json($query->orderBy('created_at', 'desc')->get(), 200);
            
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
}