<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Pengguna;
use App\Services\SimpegApiService;
use App\Services\OtpService;
use App\Models\Verification;
use App\Mail\ForgotPasswordOtpMail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    protected $simpegApi;
    protected $otp;

    public function __construct(SimpegApiService $simpegApi, OtpService $otp)
    {
        $this->simpegApi = $simpegApi;
        $this->otp = $otp;
    }

    /**
     * Autentikasi Pengguna (Login)
     * Menggunakan NIP (atau Email) dan Password kustom yang telah dibuat.
     * Dibatasi 3 kali kesempatan gagal; jika 3 kali salah, dikunci selama 3 menit.
     */
    public function login(Request $request)
    {
        $request->validate([
            'nip' => 'required|string',
            'password' => 'required|string',
        ]);

        $identifier = trim($request->nip);
        $throttleKey = 'login_failed:' . strtolower($identifier) . '|' . $request->ip();
        $maxAttempts = 3;
        $lockoutSeconds = 180; // 3 menit waktu tunggu setelah 3 kali gagal

        // Cek jika akun/IP sedang dalam masa lockout akibat 3x salah password
        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            $minutes = ceil($seconds / 60);
            return response()->json([
                'message' => "Terlalu banyak percobaan login yang gagal. Akun Anda dikunci sementara. Silakan tunggu {$minutes} menit ({$seconds} detik) sebelum mencoba kembali.",
                'retry_after' => $seconds,
                'remaining_attempts' => 0,
                'locked' => true,
            ], 429)->header('Retry-After', $seconds);
        }

        $pengguna = Pengguna::where('nip', $identifier)
            ->orWhere('email', $identifier)
            ->first();

        // Hash::check selalu dijalankan agar waktu respons tidak membedakan NIP ada/tidak ada.
        $dummy = Cache::rememberForever('auth:dummy_hash', fn () => Hash::make(Str::random(16)));
        $cocok = Hash::check($request->password, $pengguna?->kata_sandi_hash ?? $dummy);

        if (!$pengguna || !$cocok || !$pengguna->sudah_aktivasi) {
            RateLimiter::hit($throttleKey, $lockoutSeconds);
            $attempts = RateLimiter::attempts($throttleKey);
            $remaining = max(0, $maxAttempts - $attempts);

            if ($remaining === 0) {
                $seconds = RateLimiter::availableIn($throttleKey);
                $minutes = ceil($seconds / 60);
                return response()->json([
                    'message' => "Kata sandi salah. Anda telah mencapai batas maksimal {$maxAttempts} kali percobaan. Akun Anda dikunci sementara, silakan tunggu {$minutes} menit ({$seconds} detik) sebelum mencoba kembali.",
                    'retry_after' => $seconds,
                    'remaining_attempts' => 0,
                    'locked' => true,
                ], 429)->header('Retry-After', $seconds);
            }

            return response()->json([
                'message' => "NIP atau kata sandi salah. Sisa kesempatan mencoba: {$remaining} kali lagi. Jika ini pertama kali Anda masuk atau Anda lupa kata sandi, gunakan \"Lupa Kata Sandi\".",
                'remaining_attempts' => $remaining,
                'locked' => false,
            ], 401);
        }

        // Login sukses, bersihkan counter kegagalan
        RateLimiter::clear($throttleKey);

        if ($pengguna->status !== 'aktif') {
            return response()->json(['message' => 'Akun Anda sedang dinonaktifkan. Hubungi administrator BKPSDM.'], 403);
        }

        // Sinkronisasi data dengan SIMPEG agar selalu mutakhir
        $pegawai = $this->simpegApi->getPegawaiByNip($pengguna->nip);
        if ($pegawai) {
            $pengguna->update([
                'nama_lengkap' => $pegawai['nama_lengkap'],
                'jabatan' => $pegawai['jabatan'],
                'rumpun_jabatan' => $pegawai['rumpun_jabatan'],
                'unit_kerja' => $pegawai['unit_kerja'],
            ]);
        }

        // Generate token Sanctum
        $token = $pengguna->createToken('auth_token')->plainTextToken;

        // Ambil daftar peran multi-role yang sah
        $roles = $pengguna->roles_list;
        $activeRole = in_array($pengguna->peran, $roles, true) ? $pengguna->peran : ($roles[0] ?? 'peserta');

        $komunitasId = null;
        if (in_array('admin_komunitas', $roles, true)) {
            $peranRecord = \App\Models\PenggunaPeran::where('pengguna_id', $pengguna->pengguna_id)
                ->where('peran', 'admin_komunitas')
                ->first();
            $komunitasId = $peranRecord?->komunitas_id;
            if (!$komunitasId) {
                $ak = \App\Models\AdminKomunitas::where('pengguna_id', $pengguna->pengguna_id)->first();
                $komunitasId = $ak?->komunitas_id;
            }
        }

        $userData = $pengguna->toArray();
        $userData['roles'] = $roles;
        $userData['active_role'] = $activeRole;
        if ($komunitasId) {
            $userData['komunitas_id'] = $komunitasId;
        }

        return response()->json([
            'message' => 'Login berhasil',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $userData
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $roles = $user->roles_list;
        $activeRole = $request->header('X-Active-Role') ?: ($user->peran ?: ($roles[0] ?? 'peserta'));
        if (!in_array($activeRole, $roles, true)) {
            $activeRole = $roles[0] ?? 'peserta';
        }

        $userData = $user->toArray();
        $userData['roles'] = $roles;
        $userData['active_role'] = $activeRole;

        return response()->json($userData);
    }

    public function switchRole(Request $request)
    {
        $request->validate([
            'target_role' => 'required|in:admin_bkpsdm,admin_komunitas,peserta',
        ]);

        $user = $request->user();
        $targetRole = $request->target_role;
        $allowedRoles = $user->roles_list;

        if (!in_array($targetRole, $allowedRoles, true)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Akses ditolak: Anda tidak memiliki wewenang untuk peran ' . $targetRole
            ], 403);
        }

        $komunitasId = null;
        if ($targetRole === 'admin_komunitas') {
            $peranRecord = \App\Models\PenggunaPeran::where('pengguna_id', $user->pengguna_id)
                ->where('peran', 'admin_komunitas')
                ->first();
            $komunitasId = $peranRecord?->komunitas_id;
            if (!$komunitasId) {
                $ak = \App\Models\AdminKomunitas::where('pengguna_id', $user->pengguna_id)->first();
                $komunitasId = $ak?->komunitas_id;
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil beralih ke peran ' . $targetRole,
            'active_role' => $targetRole,
            'roles' => $allowedRoles,
            'komunitas_id' => $komunitasId,
            'redirect_url' => match ($targetRole) {
                'admin_bkpsdm' => '/admin',
                'admin_komunitas' => '/admin-komunitas',
                default => '/',
            }
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout berhasil'
        ]);
    }

    public function changePasswordRequestOtp(Request $request)
    {
        $pengguna = $request->user();

        if (empty($pengguna->email)) {
            return response()->json([
                'message' => 'Akun belum memiliki email resmi terdaftar. Silakan hubungi administrator BKPSDM.'
            ], 400);
        }

        // Batalkan verifikasi active sebelumnya
        Verification::where('user_id', $pengguna->pengguna_id)
            ->where('type', 'reset_password')
            ->where('status', 'active')
            ->update(['status' => 'invalid']);

        $otp = (string) random_int(100000, 999999);
        $verification = Verification::create([
            'user_id'   => $pengguna->pengguna_id,
            'unique_id' => (string) Str::uuid(),
            'otp'       => Hash::make($otp),
            'type'      => 'reset_password',
            'send_via'  => 'email',
            'resent'    => 0,
            'attempts'  => 0,
            'status'    => 'active',
            'expires_at' => now()->addMinutes(5),
        ]);

        try {
            Mail::to($pengguna->email)->queue(new ForgotPasswordOtpMail($otp));
        } catch (\Throwable $e) {
            Log::error('Gagal kirim email OTP ubah password: ' . $e->getMessage());
            return response()->json([
                'message' => 'Gagal mengirim email OTP. Silakan coba beberapa saat lagi.'
            ], 500);
        }

        return response()->json([
            'message' => 'Kode OTP telah dikirim ke email terdaftar Anda.',
            'unique_id' => $verification->unique_id,
        ]);
    }

    public function changePasswordVerify(Request $request)
    {
        $pengguna = $request->user();

        $request->validate([
            'otp' => 'required|digits:6',
            'password_sebelumnya' => 'required|string',
            'password_baru' => Pengguna::aturanKataSandi($pengguna->nip),
        ]);

        // Validasi password lama
        if (!Hash::check($request->password_sebelumnya, $pengguna->kata_sandi_hash)) {
            return response()->json([
                'message' => 'Password sebelumnya tidak sesuai.'
            ], 400);
        }

        $verification = Verification::where('user_id', $pengguna->pengguna_id)
            ->where('type', 'reset_password')
            ->where('status', 'active')
            ->latest()
            ->first();

        if (!$verification) {
            return response()->json([
                'message' => 'Kode OTP tidak valid atau sudah kedaluwarsa.'
            ], 422);
        }

        if ($verification->expires_at && now()->greaterThan($verification->expires_at)) {
            $verification->update(['status' => 'invalid']);
            return response()->json([
                'message' => 'Kode OTP tidak valid atau sudah kedaluwarsa.'
            ], 422);
        }

        if ($verification->attempts >= 5) {
            $verification->update(['status' => 'invalid']);
            return response()->json([
                'message' => 'Kode OTP tidak valid atau sudah kedaluwarsa.'
            ], 422);
        }

        $verification->increment('attempts');

        if (!Hash::check($request->otp, $verification->otp)) {
            return response()->json([
                'message' => 'Kode OTP tidak valid atau sudah kedaluwarsa.'
            ], 422);
        }

        if (Hash::check($request->password_baru, $pengguna->kata_sandi_hash)) {
            return response()->json([
                'message' => 'Password baru tidak boleh sama dengan password sebelumnya.'
            ], 400);
        }

        $verification->update(['status' => 'used']);
        $pengguna->setKataSandiPengguna($request->password_baru)->save();

        // Hapus token lain selain sesi aktif
        $currentTokenId = $pengguna->currentAccessToken()?->id;
        if ($currentTokenId) {
            $pengguna->tokens()->where('id', '!=', $currentTokenId)->delete();
        }

        return response()->json([
            'message' => 'Password berhasil diubah.'
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'nip'   => 'nullable|string',
        ]);

        $pesan = 'Jika NIP dan email sesuai dengan data kami, kode OTP telah dikirim ke email tersebut.';

        $email = strtolower(trim($request->email));
        $nip = $request->filled('nip') ? trim($request->nip) : null;
        $identifier = $nip ?: ($email ?: $request->ip());

        $verifyLockKey = 'otp_verify_lockout:' . strtolower($identifier);
        $resendLockKey = 'otp_resend_lockout:' . strtolower($identifier);

        if (RateLimiter::tooManyAttempts($verifyLockKey, 3)) {
            $seconds = RateLimiter::availableIn($verifyLockKey);
            $minutes = ceil($seconds / 60);
            return response()->json([
                'message' => "Terlalu banyak percobaan OTP yang salah (3 kali). Akses dibatasi selama 30 menit demi keamanan. Silakan tunggu {$minutes} menit ({$seconds} detik) sebelum mencoba kembali.",
                'retry_after' => $seconds,
                'locked' => true,
            ], 429)->header('Retry-After', $seconds);
        }

        if (RateLimiter::tooManyAttempts($resendLockKey, 3)) {
            $seconds = RateLimiter::availableIn($resendLockKey);
            $minutes = ceil($seconds / 60);
            return response()->json([
                'message' => "Batas pengiriman kode OTP telah tercapai (maksimal 3 kali). Akses dibatasi selama 30 menit. Silakan tunggu {$minutes} menit ({$seconds} detik) sebelum meminta kode kembali.",
                'retry_after' => $seconds,
                'locked' => true,
            ], 429)->header('Retry-After', $seconds);
        }

        $pengguna = null;
        if ($nip) {
            $pengguna = Pengguna::where('nip', $nip)->first();
            if (!$pengguna) {
                $pegawai = \App\Models\PegawaiSimpeg::where('nip', $nip)->first();
                if ($pegawai) {
                    app(\App\Services\PenggunaProvisioningService::class)->provision($pegawai);
                    $pengguna = Pengguna::where('nip', $nip)->first();
                }
            }
        } else {
            $pengguna = Pengguna::whereRaw('LOWER(email) = ?', [$email])->first();
        }

        $layak = false;
        $uniqueId = null;

        if ($pengguna && $pengguna->status === 'aktif') {
            // Jika akun belum memiliki email (data dari SIMPEG masih kosong)
            if (empty($pengguna->email)) {
                $emailTerpakaiAktif = Pengguna::whereRaw('LOWER(email) = ?', [$email])
                    ->where('pengguna_id', '!=', $pengguna->pengguna_id)
                    ->whereNotNull('kata_sandi_diatur_pada')
                    ->exists();

                if ($emailTerpakaiAktif) {
                    return response()->json([
                        'message' => 'Email ini sudah terdaftar pada akun lain yang sudah aktif.',
                    ], 422);
                }

                // Bersihkan email dari akun lain yang belum diaktivasi (misal sesi uji coba/testing)
                Pengguna::whereRaw('LOWER(email) = ?', [$email])
                    ->where('pengguna_id', '!=', $pengguna->pengguna_id)
                    ->whereNull('kata_sandi_diatur_pada')
                    ->update(['email' => null]);

                \App\Models\PegawaiSimpeg::whereRaw('LOWER(email) = ?', [$email])
                    ->where('nip', '!=', $pengguna->nip)
                    ->update(['email' => null]);

                $pengguna->email = $email;
                $pengguna->save();

                // Sinkronkan juga ke tabel pegawai_simpegs
                \App\Models\PegawaiSimpeg::where('nip', $pengguna->nip)
                    ->update(['email' => $email]);
            }

            // Layak jika email pada akun cocok dengan email yang dimasukkan
            $layak = filled($pengguna->email) && strtolower(trim($pengguna->email)) === $email;
        }

        if ($layak) {
            // Nonaktifkan verification active sebelumnya
            Verification::where('user_id', $pengguna->pengguna_id)
                ->where('type', 'reset_password')
                ->where('status', 'active')
                ->update(['status' => 'invalid']);

            $otp = (string) random_int(100000, 999999);

            $verification = Verification::create([
                'user_id'    => $pengguna->pengguna_id,
                'unique_id'  => (string) Str::uuid(),
                'otp'        => Hash::make($otp),
                'type'       => 'reset_password',
                'send_via'   => 'email',
                'resent'     => 0,
                'attempts'   => 0,
                'status'     => 'active',
                'expires_at' => now()->addMinutes(5),
            ]);

            $uniqueId = $verification->unique_id;

            try {
                Mail::to($pengguna->email)->queue(new ForgotPasswordOtpMail($otp));
            } catch (\Throwable $e) {
                Log::error('Gagal kirim OTP reset: ' . $e->getMessage());
            }
        }

        return response()->json([
            'message' => $pesan,
            'unique_id' => $uniqueId,
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'otp' => 'required|digits:6',
        ]);

        $uniqueId = $request->input('unique_id');
        $nip = $request->input('nip');

        $verification = null;
        if ($uniqueId) {
            $verification = Verification::with('user')
                ->where('unique_id', $uniqueId)
                ->where('type', 'reset_password')
                ->first();
        }

        if (!$verification && $nip) {
            $pengguna = Pengguna::where('nip', trim($nip))->first();
            if ($pengguna) {
                $verification = Verification::with('user')
                    ->where('user_id', $pengguna->pengguna_id)
                    ->where('type', 'reset_password')
                    ->latest()
                    ->first();
            }
        }

        $userNip = $verification?->user?->nip ?? ($nip ? trim($nip) : null);
        $identifier = $userNip ?: $request->ip();
        $verifyLockKey = 'otp_verify_lockout:' . strtolower($identifier);

        // 1. Cek apakah user saat ini sedang terkena lockout 30 menit
        if (RateLimiter::tooManyAttempts($verifyLockKey, 3)) {
            $seconds = RateLimiter::availableIn($verifyLockKey);
            $minutes = ceil($seconds / 60);
            return response()->json([
                'message' => "Terlalu banyak percobaan OTP yang salah (3 kali). Akses dibatasi selama 30 menit demi keamanan. Silakan tunggu {$minutes} menit ({$seconds} detik) sebelum mencoba kembali.",
                'retry_after' => $seconds,
                'remaining_attempts' => 0,
                'locked' => true,
            ], 429)->header('Retry-After', $seconds);
        }

        if (!$verification) {
            return response()->json(['message' => 'Kode OTP tidak valid atau sudah kedaluwarsa.'], 422);
        }

        if ($verification->status === 'used') {
            return response()->json(['message' => 'Sesi verifikasi ini sudah selesai digunakan. Silakan mulai kembali dari langkah awal.'], 422);
        }

        if ($verification->expires_at && now()->greaterThan($verification->expires_at)) {
            $verification->update(['status' => 'invalid']);
            return response()->json(['message' => 'OTP sudah kedaluwarsa. Silakan meminta OTP baru.'], 422);
        }

        if ($verification->attempts >= 3) {
            $verification->update(['status' => 'invalid']);
            while (RateLimiter::attempts($verifyLockKey) < 3) {
                RateLimiter::hit($verifyLockKey, 1800);
            }
            $seconds = RateLimiter::availableIn($verifyLockKey);
            $minutes = ceil($seconds / 60);
            return response()->json([
                'message' => "Anda telah mencapai batas 3 kali salah memasukkan kode OTP. Akses dibatasi selama 30 menit demi keamanan. Silakan tunggu {$minutes} menit ({$seconds} detik).",
                'retry_after' => $seconds,
                'remaining_attempts' => 0,
                'locked' => true,
            ], 429)->header('Retry-After', $seconds);
        }

        $verification->increment('attempts');

        if (!Hash::check($request->otp, $verification->otp)) {
            RateLimiter::hit($verifyLockKey, 1800);
            $currentAttempts = $verification->fresh()->attempts;
            $sisa = max(0, 3 - $currentAttempts);

            if ($sisa === 0 || RateLimiter::tooManyAttempts($verifyLockKey, 3)) {
                $verification->update(['status' => 'invalid']);
                while (RateLimiter::attempts($verifyLockKey) < 3) {
                    RateLimiter::hit($verifyLockKey, 1800);
                }
                $seconds = RateLimiter::availableIn($verifyLockKey);
                $minutes = ceil($seconds / 60);
                return response()->json([
                    'message' => "Kode OTP salah. Anda telah 3 kali salah memasukkan kode OTP. Akses dibatasi selama 30 menit demi keamanan. Silakan coba kembali setelah 30 menit.",
                    'retry_after' => $seconds,
                    'remaining_attempts' => 0,
                    'locked' => true,
                ], 429)->header('Retry-After', $seconds);
            }

            return response()->json([
                'message' => "Kode OTP tidak valid. Sisa percobaan: {$sisa} kali.",
                'remaining_attempts' => $sisa,
            ], 422);
        }

        $verification->update(['status' => 'valid']);
        RateLimiter::clear($verifyLockKey);

        return response()->json([
            'message' => 'Kode OTP berhasil diverifikasi.',
            'unique_id' => $verification->unique_id,
        ]);
    }

    public function resendOtp(Request $request)
    {
        $uniqueId = $request->input('unique_id');
        $nip = $request->input('nip');

        $verification = null;

        // 1. Cari berdasarkan unique_id terlebih dahulu
        if ($uniqueId) {
            $verification = Verification::with('user')->where('unique_id', $uniqueId)
                ->where('type', 'reset_password')
                ->first();
        }

        // 2. Jika tidak ditemukan lewat unique_id, cari lewat NIP
        if (!$verification && $nip) {
            $pengguna = Pengguna::where('nip', trim($nip))->first();
            if ($pengguna) {
                $verification = Verification::with('user')->where('user_id', $pengguna->pengguna_id)
                    ->where('type', 'reset_password')
                    ->latest()
                    ->first();
            }
        }

        $userNip = $verification?->user?->nip ?? ($nip ? trim($nip) : null);
        $identifier = $userNip ?: $request->ip();
        $verifyLockKey = 'otp_verify_lockout:' . strtolower($identifier);
        $resendLockKey = 'otp_resend_lockout:' . strtolower($identifier);

        // Cek lockout akibat salah OTP 3x
        if (RateLimiter::tooManyAttempts($verifyLockKey, 3)) {
            $seconds = RateLimiter::availableIn($verifyLockKey);
            $minutes = ceil($seconds / 60);
            return response()->json([
                'message' => "Akses sedang dibatasi selama 30 menit karena 3 kali salah memasukkan kode OTP. Silakan tunggu {$minutes} menit ({$seconds} detik).",
                'retry_after' => $seconds,
                'locked' => true,
            ], 429)->header('Retry-After', $seconds);
        }

        // Cek lockout akibat pengiriman ulang 3x
        if (RateLimiter::tooManyAttempts($resendLockKey, 3)) {
            $seconds = RateLimiter::availableIn($resendLockKey);
            $minutes = ceil($seconds / 60);
            return response()->json([
                'message' => "Batas pengiriman ulang kode OTP telah tercapai (maksimal 3 kali). Akses dibatasi selama 30 menit. Silakan tunggu {$minutes} menit ({$seconds} detik) sebelum mencoba kembali.",
                'retry_after' => $seconds,
                'locked' => true,
            ], 429)->header('Retry-After', $seconds);
        }

        // 3. Jika sesi verifikasi belum ada sama sekali, buat sesi baru jika NIP valid
        if (!$verification) {
            if ($nip) {
                $pengguna = Pengguna::where('nip', trim($nip))->first();
                if ($pengguna && filled($pengguna->email)) {
                    $otp = (string) random_int(100000, 999999);
                    $verification = Verification::create([
                        'user_id'    => $pengguna->pengguna_id,
                        'unique_id'  => (string) Str::uuid(),
                        'otp'        => Hash::make($otp),
                        'type'       => 'reset_password',
                        'send_via'   => 'email',
                        'resent'     => 1,
                        'attempts'   => 0,
                        'status'     => 'active',
                        'expires_at' => now()->addMinutes(5),
                    ]);

                    RateLimiter::hit($resendLockKey, 1800);

                    try {
                        Mail::to($pengguna->email)->queue(new ForgotPasswordOtpMail($otp));
                    } catch (\Throwable $e) {
                        Log::error('Gagal kirim resend OTP reset: ' . $e->getMessage());
                    }

                    return response()->json([
                        'message'    => 'Kode OTP baru telah dikirim ke email Anda. (Pengiriman ke-1 dari 3)',
                        'unique_id'  => $verification->unique_id,
                        'resent'     => 1,
                        'max_resend' => 3,
                    ]);
                }
            }

            return response()->json(['message' => 'Sesi verifikasi tidak ditemukan. Silakan kembali ke langkah awal.'], 404);
        }

        // 4. Jika sesi verifikasi sudah pernah selesai digunakan
        if ($verification->status === 'used') {
            return response()->json(['message' => 'Sesi verifikasi ini sudah selesai digunakan. Silakan mulai kembali dari langkah awal.'], 422);
        }

        // 5. Cek batas maksimal pengiriman ulang (maksimal 3 kali)
        if ($verification->resent >= 3) {
            while (RateLimiter::attempts($resendLockKey) < 3) {
                RateLimiter::hit($resendLockKey, 1800);
            }
            $seconds = RateLimiter::availableIn($resendLockKey);
            $minutes = ceil($seconds / 60);
            return response()->json([
                'message'    => "Batas pengiriman ulang kode OTP telah tercapai (maksimal 3 kali). Akses dibatasi selama 30 menit demi keamanan. Silakan tunggu {$minutes} menit ({$seconds} detik).",
                'retry_after' => $seconds,
                'resent'     => 3,
                'max_resend' => 3,
                'locked'     => true,
            ], 429)->header('Retry-After', $seconds);
        }

        $user = Pengguna::find($verification->user_id);
        if (!$user || empty($user->email)) {
            return response()->json(['message' => 'Akun pengguna atau alamat email tidak valid.'], 404);
        }

        // 6. Generate OTP baru & aktifkan kembali sesi
        $otp = (string) random_int(100000, 999999);
        $newResent = $verification->resent + 1;

        RateLimiter::hit($resendLockKey, 1800);
        if ($newResent >= 3) {
            while (RateLimiter::attempts($resendLockKey) < 3) {
                RateLimiter::hit($resendLockKey, 1800);
            }
        }

        $verification->update([
            'otp'        => Hash::make($otp),
            'resent'     => $newResent,
            'attempts'   => 0, // Reset attempt untuk OTP baru
            'expires_at' => now()->addMinutes(5),
            'status'     => 'active', // Pastikan aktif kembali
        ]);

        RateLimiter::clear($verifyLockKey);

        try {
            Mail::to($user->email)->queue(new ForgotPasswordOtpMail($otp));
        } catch (\Throwable $e) {
            Log::error('Gagal kirim resend OTP reset: ' . $e->getMessage());
        }

        return response()->json([
            'message'    => "Kode OTP baru telah dikirim ke email Anda. (Pengiriman ke-{$newResent} dari 3)",
            'unique_id'  => $verification->unique_id,
            'resent'     => $newResent,
            'max_resend' => 3,
        ]);
    }

    public function resetPassword(Request $request)
    {
        $uniqueId = $request->input('unique_id');
        $nip = $request->input('nip');

        $pengguna = null;
        $verification = null;

        if ($uniqueId) {
            $verification = Verification::where('unique_id', $uniqueId)
                ->where('type', 'reset_password')
                ->first();
            if ($verification) {
                $pengguna = Pengguna::find($verification->user_id);
            }
        } elseif ($nip) {
            $pengguna = Pengguna::where('nip', trim($nip))->first();
            if ($pengguna) {
                $verification = Verification::where('user_id', $pengguna->pengguna_id)
                    ->where('type', 'reset_password')
                    ->latest()
                    ->first();
            }
        }

        // Validasi aturan password baru terlebih dahulu
        $request->validate([
            'password_baru' => Pengguna::aturanKataSandi($pengguna?->nip ?? $nip),
        ]);

        $otpValid = false;

        // Jika alur 1-step (langsung kirim OTP bersama password baru)
        if ($verification && $verification->status === 'active' && $request->filled('otp')) {
            if ($verification->expires_at && now()->greaterThan($verification->expires_at)) {
                $verification->update(['status' => 'invalid']);
                return response()->json(['message' => 'Kode OTP tidak valid atau sudah kedaluwarsa.'], 422);
            }

            if ($verification->attempts >= 3) {
                $verification->update(['status' => 'invalid']);
                return response()->json(['message' => 'Kode OTP tidak valid atau sudah kedaluwarsa.'], 422);
            }

            $verification->increment('attempts');

            if (Hash::check($request->otp, $verification->otp)) {
                $verification->update(['status' => 'valid']);
                $otpValid = true;
            } else {
                return response()->json(['message' => 'Kode OTP tidak valid atau sudah kedaluwarsa.'], 422);
            }
        } elseif ($verification && $verification->status === 'valid') {
            $otpValid = true;
        }

        // Fallback untuk OtpService jika tidak menggunakan tabel verifications
        if (!$otpValid && $this->otp && $pengguna && $request->filled('otp')) {
            $otpValid = $this->otp->verifikasi('reset', $pengguna->nip, $request->otp);
        }

        if (!$pengguna || $pengguna->status !== 'aktif' || !$otpValid) {
            return response()->json([
                'message' => 'Kode OTP tidak valid atau sudah kedaluwarsa.'
            ], 422);
        }

        $pengguna->setKataSandiPengguna($request->password_baru)->save();
        $pengguna->tokens()->delete();
        if ($verification) {
            $verification->update(['status' => 'used']);
        }
        RateLimiter::clear('login_failed:' . strtolower($pengguna->nip) . '|' . $request->ip());
        RateLimiter::clear('otp_verify_lockout:' . strtolower($pengguna->nip));
        RateLimiter::clear('otp_resend_lockout:' . strtolower($pengguna->nip));

        return response()->json([
            'message' => 'Kata sandi berhasil dibuat. Silakan masuk dengan kata sandi baru Anda.'
        ]);
    }
}
