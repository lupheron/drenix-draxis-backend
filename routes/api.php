<?php

use App\Http\Controllers\AccessAuthController;
use App\Http\Controllers\AccessProfilesController;
use App\Http\Controllers\AccessRequestsController;
use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminSessionsController;
use App\Http\Controllers\AdminsController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\CompanyAnalyticsController;
use App\Http\Controllers\DriverLeadsController;
use App\Http\Controllers\EmployeeAuthController;
use App\Http\Controllers\Api\LeadSocialVerificationController;
use App\Http\Controllers\MeAttendanceController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\MeDriverLeadsController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\UserMetricsController;
use App\Http\Controllers\RingCentralController;
use App\Http\Controllers\UsersController;
use App\Http\Middleware\Cors;
use App\Http\Middleware\EnsureAttendanceManager;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureAccessAccount;
use App\Http\Middleware\EnsureAdminWriter;
use App\Http\Middleware\EnsureEmployee;
use App\Http\Middleware\EnsurePortalUser;
use App\Http\Middleware\EnsureSuperAdmin;
use Illuminate\Support\Facades\Route;

Route::group(['middleware' => [Cors::class]], function () {
    // Public
    Route::get('/access-profiles', [AccessProfilesController::class, 'index']);
    Route::post('/access-requests', [AccessRequestsController::class, 'store']);

    Route::post('/admin/login', [AdminAuthController::class, 'login']);
    Route::post('/access/login', [AccessAuthController::class, 'login']);
    Route::post('/employee/login', [EmployeeAuthController::class, 'login']);

    // Admin + Access + Employee (Client Portal) — lead social checks
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/leads/verify-socials', LeadSocialVerificationController::class);
    });

    // Admin-only management
    Route::middleware(['auth:sanctum', EnsureAdmin::class])->group(function () {
        Route::post('/admin/logout', [AdminAuthController::class, 'logout']);
        Route::get('/admin/me', [AdminAuthController::class, 'me']);

        Route::get('/access-requests/pending', [AccessRequestsController::class, 'pending']);
        Route::get('/access-requests/accepted', [AccessRequestsController::class, 'accepted']);
        Route::post('/access-requests/{id}/approve', [AccessRequestsController::class, 'approve']);
        Route::post('/access-requests/{id}/deny', [AccessRequestsController::class, 'deny']);
        Route::post('/access-requests/{id}/revoke', [AccessRequestsController::class, 'revoke']);

        // Super admin only: admins CRUD, sessions, credential resets
        Route::middleware([EnsureSuperAdmin::class])->group(function () {
            Route::get('/admins', [AdminsController::class, 'index']);
            Route::post('/admins', [AdminsController::class, 'store']);
            Route::patch('/admins/{id}', [AdminsController::class, 'update']);
            Route::patch('/admins/{id}/password', [AdminsController::class, 'updatePassword']);
            Route::delete('/admins/{id}', [AdminsController::class, 'destroy']);
            Route::delete('/admins/{id}/sessions', [AdminsController::class, 'destroySessions']);

            Route::get('/admin-sessions', [AdminSessionsController::class, 'index']);
            Route::delete('/admin-sessions/{id}', [AdminSessionsController::class, 'destroy']);
            Route::post('/admin-sessions/revoke-others', [AdminSessionsController::class, 'revokeOthers']);
        });
    });

    // Guest access users (approved external users)
    Route::middleware(['auth:sanctum', EnsureAccessAccount::class])->group(function () {
        Route::post('/access/logout', [AccessAuthController::class, 'logout']);
        Route::get('/access/me', [AccessAuthController::class, 'me']);
    });

    // Client Portal — logged-in employee only (own data)
    Route::middleware(['auth:sanctum', EnsureEmployee::class])->group(function () {
        Route::post('/employee/logout', [EmployeeAuthController::class, 'logout']);
        Route::get('/me/profile', [MeController::class, 'profile']);
        Route::post('/me/password', [MeController::class, 'password']);
        Route::get('/me/metrics', [MeController::class, 'metrics']);
        Route::get('/me/metrics/daily', [MeController::class, 'metricsDaily']);
        Route::get('/me/leads', [MeController::class, 'leads']);
        Route::get('/me/driver-leads', [DriverLeadsController::class, 'index']);
        Route::get('/me/driver-leads/search', [DriverLeadsController::class, 'search']);
        Route::post('/me/driver-leads/move', [MeDriverLeadsController::class, 'move']);
        Route::delete('/me/driver-leads', [MeDriverLeadsController::class, 'destroy']);

        Route::get('/me/attendance/summary', [MeAttendanceController::class, 'summary']);
        Route::get('/me/attendance/days', [MeAttendanceController::class, 'days']);
        Route::get('/me/attendance/days/{date}', [MeAttendanceController::class, 'day']);
        Route::post('/me/attendance/requests', [MeAttendanceController::class, 'storeRequest']);
        Route::get('/me/attendance/requests', [MeAttendanceController::class, 'requests']);
    });

    // Employee data — read for admin + access users ONLY (not employee tokens)
    Route::middleware(['auth:sanctum', EnsurePortalUser::class])->group(function () {
        Route::get('/users', [UsersController::class, 'index']);
        Route::get('/users/{id}', [UsersController::class, 'show']);
        Route::get('/users/{id}/metrics/daily', [UserMetricsController::class, 'daily']);
        Route::get('/users/{id}/ringcentral', [RingCentralController::class, 'summary']);
        Route::get('/users/{id}/ringcentral/calls', [RingCentralController::class, 'calls']);
        Route::get('/users/{id}/ringcentral/messages/conversations', [RingCentralController::class, 'messageConversations']);
        Route::get('/users/{id}/ringcentral/messages', [RingCentralController::class, 'messages']);
        Route::get('/companies/{company}/hr/analytics', [CompanyAnalyticsController::class, 'hrAnalytics']);
        Route::get('/driver-leads', [DriverLeadsController::class, 'index']);
        Route::get('/driver-leads/search', [DriverLeadsController::class, 'search']);

        Route::get('/attendance/days', [AttendanceController::class, 'days']);
        Route::get('/attendance/employees/{id}', [AttendanceController::class, 'employee']);
        Route::get('/attendance/requests', [AttendanceController::class, 'requests']);
        Route::get('/notifications', [NotificationsController::class, 'index']);
        Route::post('/notifications/{id}/read', [NotificationsController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationsController::class, 'markAllRead']);
    });

    Route::middleware(['auth:sanctum', EnsurePortalUser::class, EnsureAttendanceManager::class])->group(function () {
        Route::patch('/attendance/days/{id}', [AttendanceController::class, 'updateDay']);
        Route::post('/attendance/requests/{id}/approve', [AttendanceController::class, 'approveRequest']);
        Route::post('/attendance/requests/{id}/reject', [AttendanceController::class, 'rejectRequest']);
    });

    Route::middleware(['auth:sanctum', EnsurePortalUser::class, EnsureAdminWriter::class])->group(function () {
        Route::post('/users', [UsersController::class, 'store']);
        Route::put('/users/{id}', [UsersController::class, 'update']);
        Route::delete('/users/{id}', [UsersController::class, 'destroy']);
        Route::post('/users/{id}/portal-credentials', [UsersController::class, 'setPortalCredentials']);
    });
});
