<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Organizer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class OrganizerAuthController extends Controller
{
    // ----------------------------------------------------------------
    // 1. REGISTER: สมัครสมาชิก (รองรับทั้งการส่ง email และ emailOG)
    // ----------------------------------------------------------------
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'firstnameOG'   => 'required|string',
            'lastnameOG'    => 'required|string',
            'compName'      => 'nullable|string',
            'email'         => 'required_without:emailOG|email|unique:organizers,emailOG', // รองรับชื่อ email จาก frontend
            'emailOG'       => 'required_without:email|email|unique:organizers,emailOG',
            'password'      => 'required_without:passwordOG|string|min:6',
            'passwordOG'    => 'required_without:password|string|min:6',
            'telOG'         => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // เตรียมข้อมูล (วิชามาร WAITING_)
        $nameWithStatus = 'WAITING_' . $request->firstnameOG;
        $email = $request->email ?? $request->emailOG;
        $password = $request->password ?? $request->passwordOG;

        $organizer = Organizer::create([
            'firstnameOG'   => $nameWithStatus,
            'lastnameOG'    => $request->lastnameOG,
            'compName'      => $request->compName,
            'emailOG'       => $email,
            'passwordOG'    => Hash::make($password), // เข้ารหัสให้ถูกต้อง
            'telOG'         => $request->telOG,
        ]);

        return response()->json([
            'message' => 'สมัครสมาชิกสำเร็จ! กรุณารอแอดมินอนุมัติบัญชี',
            'data' => $organizer
        ], 201);
    }

    // ----------------------------------------------------------------
    // 2. LOGIN: เข้าสู่ระบบ (รองรับ input email/password จาก Frontend)
    // ----------------------------------------------------------------
    public function login(Request $request) 
    {
        // 1. รับค่าจาก request (รองรับทั้งแบบมี OG และไม่มี OG เพื่อไม่ให้ frontend พัง)
        $email = $request->input('email') ?? $request->input('emailOG');
        $password = $request->input('password') ?? $request->input('passwordOG');

        if (!$email || !$password) {
            return response()->json(['message' => 'กรุณากรอกอีเมลและรหัสผ่าน'], 400);
        }

        // 2. ค้นหา Organizer จากอีเมล
        $org = Organizer::where('emailOG', $email)->first();

        // 3. เช็ครหัสผ่าน
        // หมายเหตุ: ใช้ passwordOG ตามที่พี่ตั้งใน Database
        if (!$org || !Hash::check($password, $org->passwordOG)) { 
             return response()->json(['message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'], 401);
        }

        // 4. เช็ควิชามาร (WAITING_)
        if (str_starts_with($org->firstnameOG, 'WAITING_')) {
            return response()->json([
                'message' => 'บัญชีของคุณอยู่ระหว่างการตรวจสอบ (รอแอดมินอนุมัติ)'
            ], 403);
        }

        // 5. ผ่านด่าน -> สร้าง Token (ต้องรัน php artisan migrate ก่อนเพื่อให้ตารางนี้มีอยู่)
        $token = $org->createToken('organizer_token')->plainTextToken;

        return response()->json([
            'message' => 'Login สำเร็จ (Organizer)',
            'organizer' => $org,
            'token' => $token
        ], 200);
    }

    // ----------------------------------------------------------------
    // 3. GET ATTENDEES: ดึงรายชื่อผู้เข้าร่วมงาน (แก้ไข Query แล้ว ✅)
    // ----------------------------------------------------------------
    public function getAttendees(Request $request)
    {
        $orgId = auth()->id(); // ดึง ID ของ Organizer ที่ล็อกอินอยู่

        // ✅ Query ใหม่: เชื่อมตารางแบบถูกต้อง (Bookings -> Details -> TicketZones -> Events)
        $attendees = DB::table('bookings')
            // 1. เชื่อม Bookings ไปหา Details (สะพานแรก)
            ->join('booking_details', 'bookings.Booking_id', '=', 'booking_details.Booking_id')
            // 2. เชื่อม Details ไปหา Ticket Zones (สะพานสอง)
            ->join('ticket_zones', 'booking_details.TicketZone_id', '=', 'ticket_zones.TicketZone_id')
            // 3. เชื่อม Ticket Zones ไปหา Events (สะพานสาม - เจอ Event_id แล้ว!)
            ->join('events', 'ticket_zones.Event_id', '=', 'events.Event_id')
            // 4. เชื่อม Bookings ไปหา Members (เพื่อเอาชื่อคนจอง)
            ->join('members', 'bookings.Member_id', '=', 'members.Member_id')
            
            ->where('events.Org_id', $orgId) // ✅ กรองเฉพาะงานของ Organizer คนนี้
            ->select(
                'bookings.Booking_id as id',
                'members.firstname',
                'members.lastname',
                'members.email',
                'events.eventName',
                'ticket_zones.zoneName as ticket',
                'bookings.bookingStatus' // สถานะการจอง (paid, pending, cancel)
            )
            ->orderBy('bookings.created_at', 'desc')
            ->get();

        // ปรับรูปแบบข้อมูลให้ Frontend ใช้งานง่าย
        $formattedData = $attendees->map(function ($item) {
            return [
                'id'       => $item->id,
                'name'     => $item->firstname . ' ' . $item->lastname,
                'email'    => $item->email,
                'ticket'   => $item->ticket,
                'event'    => $item->eventName,
                'checkIn'  => $item->bookingStatus === 'paid' || $item->bookingStatus === 'checked_in',
            ];
        });

        return response()->json($formattedData);
    }

    // ----------------------------------------------------------------
    // 4. GET PROFILE: ดึงข้อมูลส่วนตัวผู้จัดงาน
    // ----------------------------------------------------------------
    public function getProfile(Request $request)
    {
        // ส่งข้อมูลของคนที่ Login อยู่กลับไป
        return response()->json($request->user());
    }

    // ----------------------------------------------------------------
    // 5. UPDATE PROFILE: อัปเดตข้อมูลส่วนตัว
    // ----------------------------------------------------------------
    public function updateProfile(Request $request)
    {
        $organizer = $request->user();

        // Validate ข้อมูลที่ส่งมา
        $validated = $request->validate([
            'compName'    => 'nullable|string',
            'firstnameOG' => 'required|string',
            'lastnameOG'  => 'nullable|string',
            'telOG'       => 'required|string',
            'bank_name'   => 'nullable|string',
            'bank_account'=> 'nullable|string',
        ]);

        // อัปเดตลง Database
        $organizer->update([
            'compName' => $request->compName,
            'firstnameOG' => $request->firstnameOG,
            'lastnameOG' => $request->lastnameOG,
            'telOG' => $request->telOG,
            'bank_name' => $request->bank_name,
            'bank_account' => $request->bank_account,
        ]);

        return response()->json([
            'message' => 'บันทึกข้อมูลสำเร็จ!',
            'user' => $organizer
        ]);
    }
}