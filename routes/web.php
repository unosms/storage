<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/monitoring/live', [MonitoringController::class, 'live'])->name('monitoring.live');

    Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
    Route::post('/transfers/upload', [TransferController::class, 'upload'])->name('transfers.upload');
    Route::post('/transfers/upload/chunk', [TransferController::class, 'uploadChunk'])->name('transfers.upload.chunk');
    Route::post('/transfers/upload/complete', [TransferController::class, 'uploadComplete'])->name('transfers.upload.complete');
    Route::post('/transfers/folders', [TransferController::class, 'createFolder'])->name('transfers.folders.store');
    Route::get('/transfers/download', [TransferController::class, 'download'])->name('transfers.download');

    Route::middleware('admin')->group(function () {
        Route::resource('users', UserManagementController::class)->except(['show']);
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
