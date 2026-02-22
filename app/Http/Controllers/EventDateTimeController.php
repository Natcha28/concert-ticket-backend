<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\EventDateTime;
use App\Models\Event;

class EventDateTimeController extends Controller
{
    // ฟังก์ชันเพิ่มรอบการแสดง
    public function store(Request $request)
    {
        // 1. ตรวจสอบข้อมูล
        $request->validate([
            'Event_id'     => 'required|integer',
            'roundNumber'  => 'required|integer',
            'startDT'      => 'required|date_format:Y-m-d H:i:s', // บังคับรูปแบบ วัน-เดือน-ปี เวลา
            'endDT'        => 'required|date_format:Y-m-d H:i:s',
            'Sale_startDT' => 'required|date_format:Y-m-d H:i:s',
        ]);

        // 2. เช็คก่อนว่ามี Event นี้จริงไหม (กันพลาด)
        $event = Event::find($request->Event_id);
        if (!$event) {
            return response()->json(['message' => 'ไม่พบข้อมูลคอนเสิร์ต (Event ID ไม่ถูกต้อง)'], 404);
        }

        // 3. บันทึกลง Database
        $eventDateTime = EventDateTime::create([
            'Event_id'     => $request->Event_id,
            'roundNumber'  => $request->roundNumber,
            'startDT'      => $request->startDT,
            'endDT'        => $request->endDT,
            'Sale_startDT' => $request->Sale_startDT
        ]);

        return response()->json([
            'message' => 'เพิ่มรอบแสดงเรียบร้อย!',
            'data'    => $eventDateTime
        ], 201);
    }
}