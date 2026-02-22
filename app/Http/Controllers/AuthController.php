<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // ==========================================
    // 1. ฟังก์ชันสมัครสมาชิก (Register)
    // ==========================================
    public function register(Request $request)
    {
        // ตรงนี้ต้องดูว่า React Form สมัครสมาชิกส่งชื่อตัวแปรอะไรมา
        // ถ้าส่งมาเป็น firstnameMB, emailMB ตามนี้ก็ใช้ได้เลย
        $fields = $request->validate([
            'firstnameMB' => 'required|string|max:50',
            'lastnameMB'  => 'required|string|max:50',
            'emailMB'     => 'required|string|email|max:100|unique:members,emailMB',
            'passwordMB'  => 'required|string|min:6|confirmed', 
            'telMB'       => 'required|string|max:10',
            'personalID'  => 'nullable|string|max:13',
            'gender'      => 'required|string',
        ]);

        $user = User::create([
            'firstnameMB' => $fields['firstnameMB'],
            'lastnameMB'  => $fields['lastnameMB'],
            'emailMB'     => $fields['emailMB'],
            // ใน Model User.php เราใส่ cast hashed ไว้แล้ว ส่งค่าไปตรงๆ ได้เลย
            'passwordMB'  => $fields['passwordMB'], 
            'telMB'       => $fields['telMB'],
            'personalID'  => $fields['personalID'] ?? null,
            'gender'      => $fields['gender'],
            'statusMB'    => 'ใช้งานได้',
        ]);

        $token = $user->createToken('myapptoken')->plainTextToken;

        return response()->json([
            'message' => 'สมัครสมาชิกสำเร็จ',
            'user' => $user,
            'token' => $token
        ], 201);
    }

    // ==========================================
    // 2. ฟังก์ชันเข้าสู่ระบบ (Login)
    // ==========================================
    public function login(Request $request)
    {
        // ✅ 1. รับค่า email และ password (ชื่อกลางที่ React ส่งมา)
        $fields = $request->validate([
            'email' => 'required|string',    // รับชื่อ 'email'
            'password' => 'required|string', // รับชื่อ 'password'
        ]);

        // ✅ 2. เอาค่า email ไปค้นในตาราง members (ที่คอลัมน์ emailMB)
        $user = User::where('emailMB', $fields['email'])->first();

        // ✅ 3. เช็ค Password (เทียบกับ passwordMB ในฐานข้อมูล)
        if (!$user || !Hash::check($fields['password'], $user->passwordMB)) {
            return response()->json([
                'message' => 'อีเมลหรือรหัสผ่านไม่ถูกต้อง'
            ], 401);
        }

        $token = $user->createToken('myapptoken')->plainTextToken;

        // ✅ 4. เตรียมข้อมูล User ส่งกลับ (แปลง key ให้ Frontend ใช้ง่ายๆ)
        $userData = [
            'id' => $user->Mem_id,
            'name' => $user->firstnameMB . ' ' . $user->lastnameMB,
            'email' => $user->emailMB,
            'role' => 'member', // ส่ง Role กลับไปด้วยเผื่อใช้เช็คสิทธิ์
        ];

        return response()->json([
            'message' => 'เข้าสู่ระบบสำเร็จ',
            'user' => $userData,
            'token' => $token
        ], 201);
    }

    // ==========================================
    // 3. ฟังก์ชันออกจากระบบ (Logout)
    // ==========================================
    public function logout(Request $request)
    {
        auth()->user()->tokens()->delete();

        return response()->json([
            'message' => 'ออกจากระบบเรียบร้อยแล้ว'
        ]);
    }
}