<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\RoomRateController;
use App\Http\Controllers\PeakDateController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\PromoCodeController;
use App\Http\Controllers\MaintenanceController;
use Illuminate\Support\Facades\Route;


// Home redirect
Route::get('/', function () {
    return redirect()->route('login');
});

// Authenticated group
Route::middleware('auth')->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Profile settings
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Room Board (Available to all roles including housekeeping status update)
    Route::get('/rooms', [RoomController::class, 'index'])->name('rooms.index');
    Route::post('/rooms/{room}/status', [RoomController::class, 'updateStatus'])->name('rooms.status');
    Route::post('/rooms/bulk-clean', [RoomController::class, 'bulkClean'])->name('rooms.bulkClean');
    // Admin-only room management
    Route::middleware('role:admin')->group(function () {
        Route::post('/rooms', [RoomController::class, 'store'])->name('rooms.store');
        Route::put('/rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');
        Route::delete('/rooms/{room}', [RoomController::class, 'destroy'])->name('rooms.destroy');
    });

    // Shift Session Controls (Available to Admin, Front Desk, Cashier)
    Route::middleware('role:admin,front_desk,cashier')->group(function () {
        Route::get('/shifts', [ShiftController::class, 'index'])->name('shifts.index');
        Route::post('/shifts/start', [ShiftController::class, 'start'])->name('shifts.start');
        Route::post('/shifts/end', [ShiftController::class, 'end'])->name('shifts.end');
        Route::get('/shifts/{id}/report', [ShiftController::class, 'report'])->name('shifts.report');
    });

    // Operations requiring active shifts
    Route::middleware('active_shift')->group(function () {

        // Check-In Wizard (Admin, Front Desk)
        Route::middleware('role:admin,front_desk')->group(function () {
            Route::get('/checkin', [CheckInController::class, 'index'])->name('checkin.index');
            Route::post('/checkin', [CheckInController::class, 'store'])->name('checkin.store');
            Route::post('/checkin/calculate', [CheckInController::class, 'calculate'])->name('checkin.calculate');
            Route::post('/bookings/{booking}/move', [BookingController::class, 'move'])->name('bookings.move');
            Route::put('/bookings/{booking}', [BookingController::class, 'update'])->name('bookings.update');
        });

        // Reservations / Future Bookings (Admin, Front Desk, Cashier)
        Route::middleware('role:admin,front_desk,cashier')->group(function () {
            Route::get('/reservations', [ReservationController::class, 'index'])->name('reservations.index');
        });

        // Reservation Operations (Admin, Front Desk)
        Route::middleware('role:admin,front_desk')->group(function () {
            // /reservations/create now opens a modal on the index page — redirect old URL
            Route::get('/reservations/create', fn() => redirect()->route('reservations.index'))->name('reservations.create');
            Route::post('/reservations', [ReservationController::class, 'store'])->name('reservations.store');
            Route::post('/reservations/calculate', [ReservationController::class, 'calculate'])->name('reservations.calculate');
            Route::post('/reservations/{booking}/checkin', [ReservationController::class, 'checkin'])->name('reservations.checkin');
            Route::post('/reservations/{booking}/cancel', [ReservationController::class, 'cancel'])->name('reservations.cancel');
            Route::post('/reservations/{booking}/noshow', [ReservationController::class, 'noshow'])->name('reservations.noshow');
            Route::post('/reservations/{booking}/reschedule', [ReservationController::class, 'reschedule'])->name('reservations.reschedule');
        });

        // Bookings Operations (Admin, Front Desk, Cashier)
        Route::middleware('role:admin,front_desk,cashier')->group(function () {
            Route::get('/bookings/{booking}', [BookingController::class, 'show'])->name('bookings.show');
            Route::post('/bookings/{booking}/checkout', [BookingController::class, 'checkout'])->name('bookings.checkout');
            Route::post('/bookings/{booking}/extend', [BookingController::class, 'extend'])->name('bookings.extend');
            Route::post('/bookings/{booking}/preview-extend', [BookingController::class, 'previewExtend'])->name('bookings.preview_extend');
            Route::post('/bookings/{booking}/items', [BookingController::class, 'addItems'])->name('bookings.items');
            Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
        });
    });

    // Real-time Notifications API (Admin, Front Desk, Cashier)
    Route::middleware('role:admin,front_desk,cashier')->group(function () {
        Route::get('/api/notifications', [NotificationController::class, 'getNotifications'])->name('api.notifications');
    });

    // Guest Directory (Admin, Front Desk, Cashier)
    Route::middleware('role:admin,front_desk,cashier')->group(function () {
        Route::get('/guests', [GuestController::class, 'index'])->name('guests.index');
        Route::get('/guests/search', [GuestController::class, 'search'])->name('guests.search');
        Route::get('/guests/{guest}', [GuestController::class, 'show'])->name('guests.show');
        Route::post('/guests/{guest}/vip', [GuestController::class, 'toggleVip'])->name('guests.vip');
        Route::post('/guests/sync', [GuestController::class, 'sync'])->name('guests.sync');
        Route::get('/bookings/{booking}/receipt', [BookingController::class, 'receipt'])->name('bookings.receipt');
    });

    // Inventory & Stock Controls (Admin, Front Desk)
    Route::middleware('role:admin,front_desk')->group(function () {
        Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
        Route::get('/inventory/bulk-usage', fn() => redirect()->route('inventory.index'))->name('inventory.bulk_usage');
        Route::post('/inventory', [InventoryController::class, 'store'])->name('inventory.store');
        Route::patch('/inventory/{inventoryItem}', [InventoryController::class, 'update'])->name('inventory.update');
        Route::post('/inventory/{inventoryItem}/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');
        Route::post('/inventory/use', [InventoryController::class, 'useItems'])->name('inventory.use');
    });

    // Sales & Remittances Dashboard (Admin, Front Desk, Cashier)
    Route::middleware('role:admin,front_desk,cashier')->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
        Route::get('/reports/analytics', [ReportController::class, 'analytics'])->name('reports.analytics');
    });

    // Maintenance (all authenticated roles)
    Route::get('/maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
    Route::post('/maintenance', [MaintenanceController::class, 'store'])->name('maintenance.store');
    Route::patch('/maintenance/{ticket}', [MaintenanceController::class, 'update'])->name('maintenance.update');

    // Promo code validation (all authenticated roles)
    Route::post('/promo-codes/validate', [PromoCodeController::class, 'validateCode'])->name('promo_codes.validate');


    // Admin Configurations Settings
    Route::middleware('role:admin')->prefix('settings')->name('settings.')->group(function () {
        // Rates
        Route::get('/rates', [RoomRateController::class, 'index'])->name('rates.index');
        Route::post('/rates', [RoomRateController::class, 'store'])->name('rates.store');
        Route::put('/rates/{roomType}', [RoomRateController::class, 'update'])->name('rates.update');
        Route::delete('/rates/{roomType}', [RoomRateController::class, 'destroy'])->name('rates.destroy');

        // Peak Dates
        Route::get('/peaks', [PeakDateController::class, 'index'])->name('peaks.index');
        Route::post('/peaks', [PeakDateController::class, 'store'])->name('peaks.store');
        Route::post('/peaks/{peakDate}/toggle', [PeakDateController::class, 'toggle'])->name('peaks.toggle');
        Route::delete('/peaks/{peakDate}', [PeakDateController::class, 'destroy'])->name('peaks.destroy');

        // Staff accounts
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');

        // Audit Trail Logs
        Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');

        // General Settings
        Route::get('/general', [SettingsController::class, 'index'])->name('general');
        Route::post('/general', [SettingsController::class, 'update'])->name('general.update');

        // Promo Codes CRUD
        Route::get('/promo-codes', [PromoCodeController::class, 'index'])->name('promo_codes.index');
        Route::post('/promo-codes', [PromoCodeController::class, 'store'])->name('promo_codes.store');
        Route::put('/promo-codes/{promoCode}', [PromoCodeController::class, 'update'])->name('promo_codes.update');
        Route::delete('/promo-codes/{promoCode}', [PromoCodeController::class, 'destroy'])->name('promo_codes.destroy');
    });

});

require __DIR__.'/auth.php';
