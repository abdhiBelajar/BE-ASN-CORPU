<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ForgotPasswordController;

// Web routes for Forgot Password + OTP Flow (laravel-forgot-password-otp.md)
Route::get('/forgot-password', [ForgotPasswordController::class, 'index'])->name('forgot-password');
Route::post('/forgot-password/send-otp', [ForgotPasswordController::class, 'sendOtp'])->name('forgot-password.send-otp');
Route::get('/forgot-password/verify/{uniqueId}', [ForgotPasswordController::class, 'showVerify'])->name('forgot-password.verify');
Route::put('/forgot-password/verify/{uniqueId}', [ForgotPasswordController::class, 'verifyOtp'])->name('forgot-password.verify-otp');
Route::post('/forgot-password/resend/{uniqueId}', [ForgotPasswordController::class, 'resendOtp'])->name('forgot-password.resend');
Route::get('/reset-password/{uniqueId}', [ForgotPasswordController::class, 'showResetPassword'])->name('forgot-password.reset');
Route::put('/reset-password/{uniqueId}', [ForgotPasswordController::class, 'resetPassword'])->name('forgot-password.update');

// Login named route fallback
Route::get('/login', function () {
    return redirect('/');
})->name('login');

// Catch-all SPA route
Route::get('/{any}', function () {
    $indexPath = public_path('index.html');
    if (file_exists($indexPath)) {
        return file_get_contents($indexPath);
    }
    return response("File index.html belum ditemukan.", 404);
})->where('any', '.*');
