<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TicketZone;

class TicketZoneController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'Event_id'     => 'required|integer',
            'HallZone_id'  => 'required|integer', // ถ้ายังไม่มีตาราง Hall จริงจัง ให้ส่งเลข 1 มาก่อน
            'zoneName'     => 'required|string',
            'colorZone'    => 'required|string',
            'priceperTick' => 'required|integer',
            'totalSeat'    => 'required|integer',
        ]);

        $ticketZone = TicketZone::create([
            'Event_id'     => $request->Event_id,
            'HallZone_id'  => $request->HallZone_id,
            'zoneName'     => $request->zoneName,
            'colorZone'    => $request->colorZone,
            'priceperTick' => $request->priceperTick,
            'totalSeat'    => $request->totalSeat,
            
            // ** สำคัญ **: ตอนสร้างโซนใหม่ ที่นั่งเหลือ (remain) ต้องเท่ากับจำนวนเต็ม (total) เสมอ
            'remainSeat'   => $request->totalSeat 
        ]);

        return response()->json([
            'message' => 'เพิ่มโซนบัตรเรียบร้อย!',
            'data'    => $ticketZone
        ], 201);
    }
}