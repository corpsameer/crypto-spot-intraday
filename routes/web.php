<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('cryptospot')->name('cryptospot.')->group(function (): void {
    Route::get('/', function () {
        return auth()->check()
            ? redirect()->route('cryptospot.dashboard')
            : redirect()->route('cryptospot.login');
    })->name('home');

    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [LoginController::class, 'create'])->name('login');
        Route::post('/login', [LoginController::class, 'store'])->name('login.store');
    });

    Route::middleware('auth')->group(function (): void {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::post('/logout', LogoutController::class)->name('logout');
    });
});
