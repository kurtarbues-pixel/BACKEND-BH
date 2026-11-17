<?php

use App\Http\Controllers\Api\Admin\ActivityLogController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InquiryController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\LeaseController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\MaintenanceRequestController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\RentalReviewController;
use App\Models\Notification as UserNotification;
use App\Http\Middleware\ExpireInactiveToken;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/password/forgot', [AuthController::class, 'sendPasswordResetLink'])->middleware('throttle:5,1');
Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', ExpireInactiveToken::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/profile/password', [AuthController::class, 'changePassword']);
    Route::delete('/profile', [AuthController::class, 'deleteAccount']);

    Route::get('/maintenance-requests', [MaintenanceRequestController::class, 'index']);
    Route::post('/maintenance-requests', [MaintenanceRequestController::class, 'store'])->middleware('role:tenant');
    Route::patch('/maintenance-requests/{maintenanceRequest}', [MaintenanceRequestController::class, 'update'])->middleware('role:tenant,landlord,admin');

    Route::get('/conversations', [ConversationController::class, 'index'])->middleware('role:tenant,landlord');
    Route::post('/leases/{lease}/conversation', [ConversationController::class, 'start'])->middleware('role:tenant,landlord');
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->middleware('role:tenant,landlord');
    Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'send'])->middleware('role:tenant,landlord');

    Route::get('/reviews/mine', [RentalReviewController::class, 'mine'])->middleware('role:tenant');
    Route::get('/rooms/{room}/reviews', [RentalReviewController::class, 'forRoom']);
    Route::post('/leases/{lease}/reviews', [RentalReviewController::class, 'store'])->middleware('role:tenant');
    Route::get('/admin/reviews', [RentalReviewController::class, 'index'])->middleware('role:admin');
    Route::patch('/admin/reviews/{review}', [RentalReviewController::class, 'moderate'])->middleware('role:admin');

    Route::get('/rooms', [RoomController::class, 'index']);
    Route::get('/rooms/{room}', [RoomController::class, 'show']);
    Route::get('/favorites', [FavoriteController::class, 'index'])->middleware('role:tenant');
    Route::post('/rooms/{room}/favorite', [FavoriteController::class, 'store'])->middleware('role:tenant');
    Route::delete('/rooms/{room}/favorite', [FavoriteController::class, 'destroy'])->middleware('role:tenant');
    Route::post('/rooms', [RoomController::class, 'store'])->middleware('role:landlord,staff');
    Route::put('/rooms/{room}', [RoomController::class, 'update'])->middleware('role:landlord,staff,admin');
    Route::delete('/rooms/{room}', [RoomController::class, 'destroy'])->middleware('role:landlord,staff,admin');
    Route::get('/rooms/{room}/availability-status', [RoomController::class, 'checkAvailability']);
    Route::get('/rooms/{room}/availability-calendar', [RoomController::class, 'availabilityCalendar']);
    Route::post('/rooms/{room}/availability-blocks', [RoomController::class, 'storeAvailabilityBlock'])->middleware('role:landlord,staff,admin');
    Route::delete('/rooms/{room}/availability-blocks/{block}', [RoomController::class, 'destroyAvailabilityBlock'])->middleware('role:landlord,staff,admin');
    Route::patch('/rooms/{room}/availability', [RoomController::class, 'toggleAvailability'])->middleware('role:landlord,staff,admin');
    Route::post('/rooms/{room}/images', [RoomController::class, 'uploadImages'])->middleware('role:landlord,staff');
    Route::delete('/rooms/{room}/images/{image}', [RoomController::class, 'destroyImage'])->middleware('role:landlord,staff');

    Route::post('/rooms/{room}/inquiries', [InquiryController::class, 'store'])->middleware('role:tenant');
    Route::get('/inquiries', [InquiryController::class, 'index']);
    Route::post('/inquiries/{inquiry}/approve', [InquiryController::class, 'approve'])->middleware('role:landlord,staff');
    Route::post('/inquiries/{inquiry}/reject', [InquiryController::class, 'reject'])->middleware('role:landlord,staff,admin');
    Route::post('/inquiries/{inquiry}/accept', [InquiryController::class, 'accept'])->middleware('role:tenant');
    Route::post('/inquiries/{inquiry}/decline', [InquiryController::class, 'decline'])->middleware('role:tenant');

    Route::get('/leases', [LeaseController::class, 'index']);
    Route::post('/leases', [LeaseController::class, 'store'])->middleware('role:landlord,admin');
    Route::patch('/leases/{lease}', [LeaseController::class, 'update'])->middleware('role:landlord,admin');
    Route::put('/leases/{lease}', [LeaseController::class, 'update'])->middleware('role:landlord,admin');
    Route::post('/leases/{lease}/end', [LeaseController::class, 'end'])->middleware('role:landlord,staff,admin');
    Route::post('/leases/{lease}/agreement/accept', [LeaseController::class, 'acceptAgreement'])->middleware('role:tenant');
    Route::get('/leases/{lease}/agreement.pdf', [LeaseController::class, 'agreementPdf']);
    Route::post('/leases/{lease}/inspections', [LeaseController::class, 'inspection'])->middleware('role:landlord,staff,admin');
    Route::post('/leases/{lease}/charges', [LeaseController::class, 'storeCharge'])->middleware('role:landlord,staff,admin');
    Route::post('/charges/{charge}/void', [LeaseController::class, 'voidCharge'])->middleware('role:landlord,staff,admin');

    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::post('/invoices/generate', [InvoiceController::class, 'generate'])->middleware('role:landlord,admin');
    Route::get('/invoices/current', [InvoiceController::class, 'current'])->middleware('role:tenant');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'downloadPdf']);
    Route::get('/payments/{payment}/receipt.pdf', [InvoiceController::class, 'downloadReceipt']);
    Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'pay'])->middleware('role:landlord,staff,admin');
    Route::post('/invoices/{invoice}/simulated-payments', [InvoiceController::class, 'simulatePay'])->middleware('role:tenant');

    Route::get('/landlord/dashboard', [DashboardController::class, 'landlord'])->middleware('role:landlord,admin');

    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'admin']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users', [UserController::class, 'index']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::get('/activity-logs', [ActivityLogController::class, 'index']);
    });

    Route::get('/tenants', [TenantController::class, 'index'])->middleware('role:landlord,admin');
});
