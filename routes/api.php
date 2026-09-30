<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminBkpsdm\DashboardController;
use App\Http\Controllers\Api\AdminBkpsdm\PenggunaController;
use App\Http\Controllers\Api\AdminBkpsdm\KomunitasController;
use App\Http\Controllers\Api\AdminBkpsdm\ValidasiPembelajaranController;
use App\Http\Controllers\Api\AdminBkpsdm\VerifikasiJpController;
use App\Http\Controllers\Api\AdminBkpsdm\LaporanController;
use App\Http\Controllers\Api\AdminBkpsdm\PusatBantuanController;
use App\Http\Controllers\Api\AdminBkpsdm\KategoriKursusController;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:lupa-sandi');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:reset-sandi');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/change-password/request-otp', [AuthController::class, 'changePasswordRequestOtp'])->middleware('throttle:6,1');
    Route::post('/change-password/verify', [AuthController::class, 'changePasswordVerify'])->middleware('throttle:6,1');
    Route::post('/switch-role', [AuthController::class, 'switchRole']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/kategori-kursus', [KategoriKursusController::class, 'index']);
});

// Admin BKPSDM Routes
Route::middleware(['auth:sanctum', 'role:admin_bkpsdm'])->prefix('admin-bkpsdm')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    
    // Kategori Kursus
    Route::apiResource('/kategori-kursus', KategoriKursusController::class);
    
    // Pengguna
    Route::post('/pengguna/{id}/reset-password', [PenggunaController::class, 'resetPassword']);
    Route::apiResource('/pengguna', PenggunaController::class);
    
    // Komunitas
    Route::post('/komunitas/{id}', [KomunitasController::class, 'update']);
    Route::apiResource('/komunitas', KomunitasController::class);

    // Approval Konten
    Route::get('/approval', [ValidasiPembelajaranController::class, 'index']);
    Route::get('/approval/{id}', [ValidasiPembelajaranController::class, 'show']);
    Route::post('/approval/{pembelajaran_id}', [ValidasiPembelajaranController::class, 'store']);

    // Verifikasi JP
    Route::get('/verifikasi-jp', [VerifikasiJpController::class, 'index']);
    Route::put('/verifikasi-jp/{pembelajaran_id}', [VerifikasiJpController::class, 'update']);

    // Laporan
    Route::get('/laporan/peserta', [LaporanController::class, 'peserta']);
    Route::get('/laporan/peserta/export', [LaporanController::class, 'exportPeserta']);
    Route::get('/laporan/ulasan', [LaporanController::class, 'ulasan']);

    // Pusat Bantuan
    Route::get('/tiket', [PusatBantuanController::class, 'tiket']);
    Route::put('/tiket/{id}/tanggapi', [PusatBantuanController::class, 'tanggapiTiket']);
    Route::get('/faq', [PusatBantuanController::class, 'faq']);
    Route::post('/faq', [PusatBantuanController::class, 'storeFaq']);
    Route::put('/faq/{id}', [PusatBantuanController::class, 'updateFaq']);
    Route::delete('/faq/{id}', [PusatBantuanController::class, 'destroyFaq']);
});

// Admin Komunitas Routes
Route::middleware(['auth:sanctum', 'role:admin_komunitas'])->prefix('admin-komunitas')->group(function () {
    // Dashboard
    Route::get('/dashboard', [\App\Http\Controllers\Api\AdminKomunitas\DashboardController::class, 'index']);

    // Pembelajaran
    Route::get('/pembelajaran/{id}/ulasan', [\App\Http\Controllers\Api\AdminKomunitas\PembelajaranController::class, 'getUlasan']);
    Route::delete('/pembelajaran/{id}/surat-pernyataan', [\App\Http\Controllers\Api\AdminKomunitas\PembelajaranController::class, 'hapusSuratPernyataan']);
    Route::post('/pembelajaran/{id}', [\App\Http\Controllers\Api\AdminKomunitas\PembelajaranController::class, 'update']);
    Route::apiResource('/pembelajaran', \App\Http\Controllers\Api\AdminKomunitas\PembelajaranController::class);
    Route::post('/pembelajaran/{id}/ajukan-approval', [\App\Http\Controllers\Api\AdminKomunitas\PembelajaranController::class, 'ajukanApproval']);
    Route::get('/komunitas-saya', [\App\Http\Controllers\Api\AdminKomunitas\PembelajaranController::class, 'myKomunitas']);

    // Modul & Materi
    Route::post('/modul/{id}', [\App\Http\Controllers\Api\AdminKomunitas\ModulController::class, 'update']);
    Route::post('/materi/{id}', [\App\Http\Controllers\Api\AdminKomunitas\MateriController::class, 'update']);
    Route::apiResource('/pembelajaran.modul', \App\Http\Controllers\Api\AdminKomunitas\ModulController::class)->shallow();
    Route::apiResource('/modul.materi', \App\Http\Controllers\Api\AdminKomunitas\MateriController::class)->shallow();

    // Evaluasi
    Route::apiResource('/modul.kuis', \App\Http\Controllers\Api\AdminKomunitas\KuisController::class)->shallow();
    Route::apiResource('/pembelajaran.post-test', \App\Http\Controllers\Api\AdminKomunitas\PostTestController::class)->shallow();

    // JP & Monitoring
    Route::post('/pembelajaran/{id}/jp', [\App\Http\Controllers\Api\AdminKomunitas\PembelajaranJpController::class, 'store']);
    Route::get('/pembelajaran/{id}/peserta', [\App\Http\Controllers\Api\AdminKomunitas\MonitoringController::class, 'index']);
    Route::get('/laporan-progress', [\App\Http\Controllers\Api\AdminKomunitas\MonitoringController::class, 'laporanProgress']);

    // Pusat Bantuan
    Route::get('/faq', [\App\Http\Controllers\Api\AdminKomunitas\PusatBantuanController::class, 'faq']);
    Route::post('/tiket', [\App\Http\Controllers\Api\AdminKomunitas\PusatBantuanController::class, 'storeTiket']);
    Route::get('/tiket', [\App\Http\Controllers\Api\AdminKomunitas\PusatBantuanController::class, 'myTiket']);
});

// Peserta (User) Routes
Route::middleware(['auth:sanctum', 'role:peserta'])->prefix('user')->group(function () {
    // Dashboard
    Route::get('/dashboard', [\App\Http\Controllers\Api\User\DashboardController::class, 'index']);

    // Katalog & Pendaftaran
    Route::get('/katalog', [\App\Http\Controllers\Api\User\KatalogController::class, 'index']);
    Route::post('/katalog/{pembelajaran_id}/enroll', [\App\Http\Controllers\Api\User\KatalogController::class, 'enroll']);

    // Pelatihanku & Detail Pelatihan
    Route::get('/my-courses', [\App\Http\Controllers\Api\User\MyCourseController::class, 'index']);
    Route::get('/courses/{id}', [\App\Http\Controllers\Api\User\CourseDetailController::class, 'show']);
    Route::post('/courses/{id}/mark-read', [\App\Http\Controllers\Api\User\CourseDetailController::class, 'markRead']);
    Route::post('/courses/{id}/ulasan', [\App\Http\Controllers\Api\User\CourseDetailController::class, 'submitUlasan']);
    Route::post('/courses/{pembelajaran_id}/materi/{materi_id}/read', [\App\Http\Controllers\Api\User\CourseDetailController::class, 'markMateriAsRead']);

    // Post Test, Kuis, Pre-Test & Sertifikat
    Route::get('/courses/{pembelajaran_id}/modul/{modul_id}/kuis/{kuis_id}', [\App\Http\Controllers\Api\User\KuisController::class, 'show']);
    Route::post('/courses/{pembelajaran_id}/modul/{modul_id}/kuis/{kuis_id}/submit', [\App\Http\Controllers\Api\User\KuisController::class, 'submit']);
    Route::get('/courses/{pembelajaran_id}/materi/{materi_id}/pre-test', [\App\Http\Controllers\Api\User\KuisController::class, 'showPreTest']);
    Route::post('/courses/{pembelajaran_id}/materi/{materi_id}/pre-test/submit', [\App\Http\Controllers\Api\User\KuisController::class, 'submitPreTest']);

    Route::get('/courses/{pembelajaran_id}/post-test', [\App\Http\Controllers\Api\User\PostTestController::class, 'show']);
    Route::post('/courses/{pembelajaran_id}/post-test/submit', [\App\Http\Controllers\Api\User\PostTestController::class, 'submit']);
    Route::get('/certificates', [\App\Http\Controllers\Api\User\SertifikatController::class, 'index']);
    Route::get('/certificates/{id}/download', [\App\Http\Controllers\Api\User\SertifikatController::class, 'download']);
    Route::get('/courses/{pembelajaran_id}/certificate/download', [\App\Http\Controllers\Api\User\SertifikatController::class, 'downloadByCourse']);

    // Pusat Bantuan
    Route::post('/bantuan/tiket', [\App\Http\Controllers\Api\User\BantuanController::class, 'submitTiket']);

    // Komunitas
    Route::get('/komunitas', [\App\Http\Controllers\Api\User\KomunitasController::class, 'index']);
    Route::post('/komunitas/{id}/join', [\App\Http\Controllers\Api\User\KomunitasController::class, 'join']);
});
