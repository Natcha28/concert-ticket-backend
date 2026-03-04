<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash; // ✅ ตำแหน่งที่ถูกต้องต้องอยู่ตรงนี้ (บนสุดของไฟล์)

class ProfileController extends Controller
{
    // 1. ฟังก์ชันดึงข้อมูลโปรไฟล์
    public function getProfile(Request $request)
    {
        $user = $request->user(); 
        
        return response()->json([
            'id' => $user->Mem_id,
            'firstName' => $user->firstnameMB,
            'lastName' => $user->lastnameMB,
            'email' => $user->emailMB,
            'phone' => $user->telMB,
            'idCard' => $user->personalID,
            'gender' => $user->gender,
        ]);
    }

    // 2. ฟังก์ชันอัปเดตข้อมูลโปรไฟล์ (ชื่อ, เบอร์โทร ฯลฯ)
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $fields = $request->validate([
            'firstName' => 'required|string|max:50',
            'lastName'  => 'required|string|max:50',
            'phone'     => 'required|string|max:10',
            'idCard'    => 'nullable|string|max:13',
            'gender'    => 'required|string',
        ]);

        $user->update([
            'firstnameMB' => $fields['firstName'],
            'lastnameMB'  => $fields['lastName'],
            'telMB'       => $fields['phone'],
            'personalID'  => $fields['idCard'],
            'gender'      => $fields['gender'],
        ]);

        return response()->json([
            'message' => 'อัปเดตข้อมูลสำเร็จ',
            'user' => $user
        ]);
    }

    // 3. ฟังก์ชันเปลี่ยนรหัสผ่าน
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'currentPassword' => 'required|string',
            'newPassword' => 'required|string|min:8',
            'confirmPassword' => 'required|string|same:newPassword',
        ]);

        // ตรวจสอบรหัสผ่านเก่าด้วย Hash::check
        if (!Hash::check($request->currentPassword, $user->passwordMB)) {
            return response()->json([
                'message' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง'
            ], 400);
        }

        // บันทึกรหัสผ่านใหม่
        $user->passwordMB = $request->newPassword;
        $user->save();

        return response()->json([
            'message' => 'เปลี่ยนรหัสผ่านสำเร็จ'
        ]);
    }
}