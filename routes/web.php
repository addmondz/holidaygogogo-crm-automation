<?php

use App\Http\Controllers\Admin\AgentController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::patch('availability', AvailabilityController::class)->name('availability.update');

    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('agents', [AgentController::class, 'index'])->name('agents.index');
        Route::post('agents', [AgentController::class, 'store'])->name('agents.store');
        Route::patch('agents/{agent}', [AgentController::class, 'update'])->name('agents.update');
        Route::delete('agents/{agent}', [AgentController::class, 'destroy'])->name('agents.destroy');

        Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::patch('settings', [SettingsController::class, 'update'])->name('settings.update');
    });
});

require __DIR__.'/settings.php';
