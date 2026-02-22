<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

// Import Controllers
use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrganizerAuthController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventDateTimeController;
use App\Http\Controllers\TicketZoneController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\OrganizerPaymentController;
use App\Http\Controllers\AdminEventController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// ====================================================
// 1. PUBLIC ROUTES (ไม่ต้องล็อกอินก็เข้าได้)
// ====================================================

// --- Authentication ---
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/organizer/login', [OrganizerAuthController::class, 'login']);
Route::post('/organizer/register', [OrganizerAuthController::class, 'register']);

// --- Public View (จัดกลุ่มให้ตรงกับ Frontend) ---
Route::prefix('public')->group(function () {
    Route::get('/events', [EventController::class, 'getPublicEvents']);   // สำหรับหน้าแรก
    Route::get('/events/{id}', [EventController::class, 'show']);         // ✅ สำหรับหน้ารายละเอียด (ต้องมีคำว่า public นำหน้า)
});

// ====================================================
// 2. PROTECTED ROUTES (ต้องมี Token เท่านั้น)
// ====================================================
Route::group(['middleware' => ['auth:sanctum']], function () {

    // --- User Info ---
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // --- Member (คนซื้อบัตร) ---
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::get('/my-tickets', [BookingController::class, 'index']);

    // --- Organizer (ผู้จัดงาน) ---
    // 🔴 [สำคัญ] ส่วนจัดการ Events 🔴
    
    // ✅ เพิ่มบรรทัดนี้ เพื่อให้ Frontend เรียก GET /api/events ได้ (แก้ปัญหา Method Not Allowed)
    Route::get('/events', [EventController::class, 'index']); 

    Route::get('/events/{id}', [EventController::class, 'show']);
    Route::put('/events/{id}', [EventController::class, 'update']);
    Route::delete('/events/{id}', [EventController::class, 'destroy']); 
    
    Route::post('/events', [EventController::class, 'store']);           // สร้างงานใหม่
    Route::get('/my-events', [EventController::class, 'index']);         // ดูงานของฉัน (Dashboard)
    
    Route::post('/event-times', [EventDateTimeController::class, 'store']); 
    Route::post('/ticket-zones', [TicketZoneController::class, 'store']);   
    
    Route::post('/organizer/pay-deposit', [OrganizerPaymentController::class, 'payDeposit']);
    Route::get('/organizer/attendees', [OrganizerAuthController::class, 'getAttendees']);
    Route::get('/organizer/profile', [OrganizerAuthController::class, 'getProfile']);
    Route::post('/organizer/profile', [OrganizerAuthController::class, 'updateProfile']);

    // --- Admin ---
    Route::get('/admin/pending-events', [AdminEventController::class, 'getPendingEvents']);
    Route::post('/admin/approve-deposit', [AdminEventController::class, 'approveDeposit']);
    Route::post('/admin/approve-organizer', [AdminEventController::class, 'approveOrganizer']);
});

// --- Check DB ---
Route::get('/check-db', function () {
    try {
        return "Database Connected: " . DB::connection()->getDatabaseName();
    } catch (\Exception $e) {
        return "Error: " . $e->getMessage();
    }
});