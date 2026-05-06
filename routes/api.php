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
use App\Http\Controllers\SeatController; 
use App\Http\Controllers\OrderController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\NotificationController;

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

// --- Public View ---
Route::prefix('public')->group(function () {
    Route::get('/events', [EventController::class, 'getPublicEvents']);
    Route::get('/events/{id}', [EventController::class, 'show']);
});

Route::get('/seats', [SeatController::class, 'getSeats']);
Route::get('/public/events', [EventController::class, 'getPublicEvents']);

// API สำหรับจัดการการชำระเงิน (จำลองว่าธนาคารยิงมา ไม่ต้องล็อกอิน)
Route::post('/payment/success/{bookingId}', [BookingController::class, 'confirmPayment']);
Route::post('/payment/cancel/{bookingId}', [BookingController::class, 'cancelPayment']);

// API สำหรับให้หน้า E-Ticket ดึงข้อมูลไปโชว์ (เปิดเป็น Public เผื่อเจ้าหน้าที่สแกน)
Route::get('/tickets/{bookingId}', [BookingController::class, 'getTickets']);

Route::get('/admin/organizers', [AdminController::class, 'getOrganizers']);
Route::get('/admin/events/{id}', [AdminEventController::class, 'getEventDetail']);

Route::get('/user/notifications', [NotificationController::class, 'index']);

Route::get('/admin/members', [AdminEventController::class, 'getMembers']);
Route::get('/admin/events', [AdminEventController::class, 'getAllEvents']);
Route::get('/admin/dashboard-stats', [AdminController::class, 'getDashboardStats']);
Route::post('/admin/organizers/{id}/status', [AdminController::class, 'updateOrganizerStatus']);
Route::patch('/events/{id}/status', [AdminEventController::class, 'updateStatus']);

// ✅ Route อัปเดตข้อมูลอีเวนต์ (ย้ายมาโซน Public อันเดียวเดี่ยวๆ ไม่ซ้ำซ้อน)
Route::put('/events/{id}', [EventController::class, 'update']);

Route::get('/admin/payouts', [App\Http\Controllers\AdminController::class, 'getPayouts']);
Route::get('/admin/orders', [App\Http\Controllers\AdminController::class, 'getOrders']);

Route::get('/admin/finance-summary', [App\Http\Controllers\AdminController::class, 'getFinanceSummary']);
Route::get('/admin/events-list', [App\Http\Controllers\AdminController::class, 'getAdminEvents']);
Route::get('/admin/events/{id}/sales', [App\Http\Controllers\AdminController::class, 'getEventSalesDetail']);
// (ลบ Route /admin/payouts ที่ซ้ำซ้อนตรงนี้ออกไป 1 บรรทัดเรียบร้อยแล้ว)

// ====================================================
// 2. PROTECTED ROUTES (ต้องมี Token ล็อกอินเท่านั้น)
// ====================================================
Route::group(['middleware' => ['auth:sanctum']], function () {

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // --- Authentication ---
    Route::post('/logout', [AuthController::class, 'logout']); 

    // --- Member (คนซื้อบัตร) ---
    Route::get('/profile', [App\Http\Controllers\ProfileController::class, 'getProfile']);
    Route::put('/profile', [App\Http\Controllers\ProfileController::class, 'updateProfile']);
    Route::put('/profile/password', [App\Http\Controllers\ProfileController::class, 'updatePassword']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::get('/my-tickets', [BookingController::class, 'index']);
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::get('/orders', [\App\Http\Controllers\BookingController::class, 'index']);
    Route::get('/orders/{id}', [\App\Http\Controllers\BookingController::class, 'getOrderDetails']);
    
    Route::get('/events/{id}', [EventController::class, 'show']);
    Route::delete('/events/{id}', [EventController::class, 'destroy']); 
    
    // --- Organizer (ผู้จัดงาน) ---  
    Route::post('/events', [EventController::class, 'store']);
    Route::get('/my-events', [EventController::class, 'index']);
    Route::post('/event-times', [EventDateTimeController::class, 'store']); 
    Route::post('/ticket-zones', [TicketZoneController::class, 'store']);   
    Route::post('/organizer/pay-deposit', [OrganizerPaymentController::class, 'payDeposit']);
    Route::get('/organizer/attendees', [OrganizerAuthController::class, 'getAttendees']);
    Route::get('/organizer/profile', [OrganizerAuthController::class, 'getProfile']);
    Route::post('/organizer/profile', [OrganizerAuthController::class, 'updateProfile']);
    Route::middleware('auth:sanctum')->get('/organizer/attendees', [EventController::class, 'getAttendees']);
    Route::get('/organizer/sales', [EventController::class, 'getSalesAnalytics']);
    Route::get('/organizer/dashboard', [EventController::class, 'getDashboardData']);
    Route::get('/organizer/events/{id}', [EventController::class, 'show']);
    Route::post('/generate-promptpay', [PaymentController::class, 'generatePromptPay']);
    Route::get('/events', [EventController::class, 'index']);
    Route::post('/organizer/events/{id}/publish-payment', [PaymentController::class, 'publishPayment']);
    Route::post('/organizer/events/{id}/simulate-pay', [EventController::class, 'simulatePayment']); // ✅ เปลี่ยนจาก EventPaymentController เป็น EventController


    // --- Admin ---
    Route::get('/admin/pending-events', [AdminEventController::class, 'getPendingEvents']);
    Route::post('/admin/approve-deposit', [AdminEventController::class, 'approveDeposit']);
    Route::post('/admin/approve-organizer', [AdminEventController::class, 'approveOrganizer']);
    Route::get('/admin/orders/{id}', [App\Http\Controllers\AdminController::class, 'getOrderDetail']);
});  

// --- Check DB ---
Route::get('/check-db', function () {
    try {
        return "Database Connected: " . DB::connection()->getDatabaseName();
    } catch (\Exception $e) {
        return "Error: " . $e->getMessage();
    }
});