<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Event;
use App\Models\EventDateTime;
use App\Models\TicketZone;
use App\Models\HallZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class EventController extends Controller
{
    // ----------------------------------------------------------------
    // 1. GET ALL: ดึงข้อมูลงานคอนเสิร์ตทั้งหมด
    // ----------------------------------------------------------------
    public function index()
    {
       $orgId = auth()->id(); 

        $events = Event::with(['hall', 'ticket_zones']) 
                        ->where('Org_id', $orgId)
                        ->orderBy('created_at', 'desc')
                        ->get();

        $events->transform(function ($event) {
            $totalSeats = $event->ticket_zones->sum('totalSeat');
            $remainSeats = $event->ticket_zones->sum('remainSeat');
            $soldSeats = $totalSeats - $remainSeats;

            $imageUrl = null;
            if ($event->bannerImage) {
                if (str_starts_with($event->bannerImage, 'http')) {
                    $imageUrl = $event->bannerImage;
                } else {
                    $imageUrl = asset('storage/' . $event->bannerImage);
                }
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

    // ----------------------------------------------------------------
    // 2. GET ONE: ดูรายละเอียดงานรายตัว
    // ----------------------------------------------------------------
    public function show($id)
    {
        $event = Event::with(['hall', 'event_date_times', 'ticket_zones']) 
              ->where('Event_id', $id)
              ->first();
        
       if (!$event) {
        return response()->json(['message' => 'Not Found'], 404);
        }

        return response()->json($event);
    }

    // ----------------------------------------------------------------
    // 3. POST: สร้างงานใหม่ และ เช็คคิวฮอลล์ชน
    // ----------------------------------------------------------------
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

            $approvedEvents = Event::with('event_date_times')
                ->where('Hall_id', $request->Hall_id)
                ->whereIn('eventStatus', ['กำลังจะจัด', 'กำลังจัดแสดง'])
                ->get();

            $conflictEvent = null;
            $conflictDT = null;

            foreach ($approvedEvents as $existingEvent) {
                foreach ($existingEvent->event_date_times as $dt) {
                    $existingSetup = Carbon::parse($dt->startDT)->subDays(2)->startOfDay();
                    $existingTeardown = Carbon::parse($dt->endDT)->addDays(2)->endOfDay();

                    if ($rentalStart <= $existingTeardown && $rentalEnd >= $existingSetup) {
                        $conflictEvent = $existingEvent;
                        $conflictDT = $dt;
                        break 2;
                    }
                }
            }

            if ($conflictEvent && $conflictDT) {
                $bookedUntil = Carbon::parse($conflictDT->endDT)->addDays(2);
                return response()->json([
                    'success' => false,
                    'code' => 'VENUE_UNAVAILABLE',
                    'lastBookedDate' => $bookedUntil->toDateTimeString(), 
                    'message' => '❌ ไม่สามารถจองได้: คิวฮอลล์ทับซ้อนกับงาน "' . $conflictEvent->eventName . '" (รวมเวลาเซ็ตอัปและรื้อถอน)'
                ], 409);
            }

            $event = new Event();
            $event->Org_id = auth()->id() ?? 4; 
            $event->Hall_id = $request->Hall_id;
            $event->eventName = $request->eventName;
            
            $gridDataJson = json_encode([
                'venue_size' => $request->venueSize,
                'stage_grid' => isset($request->stageGrid) ? json_decode($request->stageGrid) : []
            ]);
            $event->eventDescription = $request->eventDescription . "||GRID_DATA||" . $gridDataJson;
            $event->bannerImage = $request->posterImage;
            $event->MaxTicketsPerMember = 4;
            $event->eventStatus = 'กำลังเตรียม'; 
            $event->rental_start = $rentalStart;
            $event->rental_end   = $rentalEnd;
            $event->save();

            foreach ($request->show_rounds as $round) {
                $dt = new EventDateTime();
                $dt->Event_id = $event->Event_id;
                $dt->roundNumber = $round['roundNumber'];
                $dt->startDT = $round['startDT'];
                $dt->endDT   = $round['endDT'];
                $dt->Sale_startDT = $round['saleStart'];
                $dt->Sale_endDT   = $round['saleEnd'] ?? null;
                $dt->save();
            }

            if ($request->has('tickets')) {
                foreach ($request->tickets as $index => $t) {
                    $hallZone = HallZone::create([
                        'Hall_id'      => $request->Hall_id,
                        'zoneName'     => $t['zoneName'] ?? 'Zone ' . ($index + 1),
                        'zoneCapacity' => $t['quantity'] ?? 0
                    ]);

                    $ticketZone = new TicketZone();
                    $ticketZone->Event_id = $event->Event_id;
                    $ticketZone->HallZone_id = $hallZone->HallZone_id;
                    $ticketZone->zoneName = $t['zoneName'];
                    $ticketZone->colorZone = '#FFFFFF'; 
                    $ticketZone->priceperTick = $t['price'];
                    $ticketZone->totalSeat = $t['quantity'];
                    $ticketZone->remainSeat = $t['quantity'];
                    $ticketZone->save();
                }
            }

            DB::commit(); 
            return response()->json(['message' => 'บันทึกสำเร็จ', 'event_id' => $event->Event_id], 201);

        } catch (\Exception $e) {
            DB::rollBack(); 
            return response()->json(['message' => 'Server Error: ' . $e->getMessage()], 500);
        }
    }

    // ----------------------------------------------------------------
    // ฟังก์ชันช่วยแปลง Base64 เป็นไฟล์รูปภาพ
    // ----------------------------------------------------------------
    private function saveBase64Image($base64String, $folder)
    {
        if (preg_match('/^data:image\/(\w+);base64,/', $base64String, $type)) {
            $base64String = substr($base64String, strpos($base64String, ',') + 1);
            $type = strtolower($type[1]); 

            if (!in_array($type, [ 'jpg', 'jpeg', 'gif', 'png' ])) { return null; }
            $base64String = base64_decode($base64String);
            if ($base64String === false) { return null; }

            $fileName = Str::random(10) . '.' . $type;
            $filePath = $folder . '/' . $fileName;
            Storage::disk('public')->put($filePath, $base64String);

            return $filePath;
        }
        return null;
    }

    // ----------------------------------------------------------------
    // ฟังก์ชันดึงงานทั้งหมดมาโชว์หน้าเว็บ (สำหรับคนทั่วไป)
    // ----------------------------------------------------------------
    public function getPublicEvents()
    {
        $allowedStatuses = ['On Sale', 'Selling', 'กำลังจะจัด', 'เปิดขายบัตร'];
        $events = Event::with(['hall', 'ticket_zones'])
            ->whereIn('eventStatus', $allowedStatuses)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($events);
    }

    // ----------------------------------------------------------------
    // 4. PUT: อัปเดตข้อมูลงาน (แก้ไข)
    // ----------------------------------------------------------------
    public function update(Request $request, $id)
    {
        DB::beginTransaction(); 
        try {
            $event = Event::with('event_date_times')->where('Event_id', $id)->first();
            
            if (!$event) {
                return response()->json(['message' => 'ไม่พบข้อมูลงาน'], 404);
            }

            if ($event->Org_id != auth()->id()) {
                return response()->json(['message' => 'ไม่มีสิทธิ์แก้ไขงานนี้'], 403);
            }

            if (in_array($event->eventStatus, ['เปิดขายบัตร', 'กำลังจัดแสดง', 'จบการแสดง'])) {
                return response()->json(['message' => 'ไม่สามารถแก้ไขงานที่เปิดขายบัตรไปแล้วได้'], 403);
            }

            if ($request->has('show_rounds') && count($request->show_rounds) > 0) {
                $rounds = collect($request->show_rounds);
                $firstShowDate = Carbon::parse($rounds->min('startDT'));
                $lastShowDate  = Carbon::parse($rounds->max('endDT'));

                $rentalStart = $firstShowDate->copy()->subDays(2)->startOfDay(); 
                $rentalEnd   = $lastShowDate->copy()->addDays(2)->endOfDay();

                $approvedEvents = Event::with('event_date_times')
                    ->where('Hall_id', $event->Hall_id)
                    ->where('Event_id', '!=', $id) 
                    ->whereIn('eventStatus', ['กำลังจะจัด', 'กำลังจัดแสดง'])
                    ->get();

                $conflictEvent = null;
                $conflictDT = null;

                foreach ($approvedEvents as $existingEvent) {
                    foreach ($existingEvent->event_date_times as $dt) {
                        $existingSetup = Carbon::parse($dt->startDT)->subDays(2)->startOfDay();
                        $existingTeardown = Carbon::parse($dt->endDT)->addDays(2)->endOfDay();

                        if ($rentalStart <= $existingTeardown && $rentalEnd >= $existingSetup) {
                            $conflictEvent = $existingEvent;
                            $conflictDT = $dt;
                            break 2;
                        }
                    }
                }

                if ($conflictEvent && $conflictDT) {
                    $bookedUntil = Carbon::parse($conflictDT->endDT)->addDays(2);
                    return response()->json([
                        'success' => false,
                        'code' => 'VENUE_UNAVAILABLE',
                        'lastBookedDate' => $bookedUntil->toDateTimeString(), 
                        'message' => '❌ ไม่สามารถแก้ไขวันได้: วันใหม่ที่คุณเลือกไปทับซ้อนกับงาน "' . $conflictEvent->eventName . '"'
                    ], 409);
                }

                EventDateTime::where('Event_id', $id)->delete();

                foreach ($request->show_rounds as $round) {
                    $dt = new EventDateTime();
                    $dt->Event_id = $event->Event_id;
                    $dt->roundNumber = $round['roundNumber'];
                    $dt->startDT = $round['startDT'];
                    $dt->endDT   = $round['endDT'];
                    $dt->Sale_startDT = $round['saleStart'];
                    $dt->Sale_endDT   = $round['saleEnd'] ?? null;
                    $dt->save();
                }

                $event->rental_start = $rentalStart;
                $event->rental_end   = $rentalEnd;
            }

            $event->eventName = $request->eventName ?? $event->eventName;
            
            if ($request->has('eventDescription')) {
                $oldGrid = "";
                if (str_contains($event->eventDescription, '||GRID_DATA||')) {
                    $parts = explode('||GRID_DATA||', $event->eventDescription);
                    $oldGrid = "||GRID_DATA||" . ($parts[1] ?? '{}');
                }
                $event->eventDescription = $request->eventDescription . $oldGrid;
            }

            if ($request->posterImage) {
                $event->bannerImage = $request->posterImage;
            }

            if ($request->has('tickets') && count($request->tickets) > 0) {
                $oldTicketZones = TicketZone::where('Event_id', $id)->get();
                foreach($oldTicketZones as $tz) {
                    HallZone::where('HallZone_id', $tz->HallZone_id)->delete();
                    $tz->delete();
                }

                foreach ($request->tickets as $index => $t) {
                    $hallZone = HallZone::create([
                        'Hall_id'      => $event->Hall_id,
                        'zoneName'     => $t['zoneName'] ?? 'Zone ' . ($index + 1),
                        'zoneCapacity' => $t['quantity'] ?? 0
                    ]);

                    $ticketZone = new TicketZone();
                    $ticketZone->Event_id = $event->Event_id;
                    $ticketZone->HallZone_id = $hallZone->HallZone_id;
                    $ticketZone->zoneName = $t['zoneName'];
                    $ticketZone->colorZone = '#FFFFFF'; 
                    $ticketZone->priceperTick = $t['price'];
                    $ticketZone->totalSeat = $t['quantity'];
                    $ticketZone->remainSeat = $t['quantity']; 
                    $ticketZone->save();
                }
            }

            $event->eventStatus = 'กำลังเตรียม'; 
            $event->save();
            DB::commit();

            return response()->json(['message' => 'อัปเดตข้อมูลสำเร็จ กรุณารอแอดมินอนุมัติอีกครั้ง', 'event' => $event]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Server Error: ' . $e->getMessage()], 500);
        }
    } // ✅ สิ้นสุดฟังก์ชัน update

    // ----------------------------------------------------------------
    // 5. DELETE: ลบข้อมูลงาน
    // ----------------------------------------------------------------
    public function destroy($id)
    {
        DB::beginTransaction(); 
        try {
            $event = Event::where('Event_id', $id)->first();

            if (!$event) {
                return response()->json(['message' => 'ไม่พบข้อมูลงาน'], 404);
            }

            if ($event->Org_id != auth()->id()) {
                return response()->json(['message' => 'ไม่มีสิทธิ์ลบงานนี้'], 403);
            }

            if (in_array($event->eventStatus, ['เปิดขายบัตร', 'กำลังจัดแสดง', 'จบการแสดง'])) {
                return response()->json(['message' => 'ไม่สามารถลบงานที่เปิดขายบัตรไปแล้วได้'], 403);
            }

            EventDateTime::where('Event_id', $id)->delete();
            
            $ticketZones = TicketZone::where('Event_id', $id)->get();
            foreach($ticketZones as $tz) {
                HallZone::where('HallZone_id', $tz->HallZone_id)->delete();
                $tz->delete();
            }

            $event->delete();

            DB::commit();
            return response()->json(['message' => 'ลบข้อมูลสำเร็จ']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Server Error: ' . $e->getMessage()], 500);
        }
    } // ✅ สิ้นสุดฟังก์ชัน destroy

} // ✅ สิ้นสุด Class