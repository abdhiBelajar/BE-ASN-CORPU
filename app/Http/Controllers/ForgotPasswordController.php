<?php

namespace App\Http\Controllers;

use App\Mail\ForgotPasswordOtpMail;
use App\Models\Pengguna;
use App\Models\Verification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class ForgotPasswordController extends Controller
{
    /**
     * Halaman Forgot Password
     */
    public function index()
    {
        return view('auth.forgot-password');
    }

    /**
     * Generate dan kirim OTP.
     */
    public function sendOtp(Request $request)
    {
        $request->validate([
            'email' => [
                'required',
                'email',
            ],
            'nip' => [
                'nullable',
                'string',
            ],
        ]);

        $email = strtolower(trim($request->email));
        $nip = $request->filled('nip') ? trim($request->nip) : null;
        $identifier = $nip ?: ($email ?: $request->ip());

        $verifyLockKey = 'otp_verify_lockout:' . strtolower($identifier);
        $resendLockKey = 'otp_resend_lockout:' . strtolower($identifier);

        if (RateLimiter::tooManyAttempts($verifyLockKey, 3)) {
            $seconds = RateLimiter::availableIn($verifyLockKey);
            $minutes = ceil($seconds / 60);
            $errMsg = "Terlalu banyak percobaan OTP yang salah (3 kali). Akses dibatasi selama 30 menit demi keamanan. Silakan tunggu {$minutes} menit ({$seconds} detik) sebelum mencoba kembali.";
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $errMsg,
                    'retry_after' => $seconds,
                    'locked' => true,
                ], 429)->header('Retry-After', $seconds);
            }
            return back()->with('error', $errMsg);
        }

        if (RateLimiter::tooManyAttempts($resendLockKey, 3)) {
            $seconds = RateLimiter::availableIn($resendLockKey);
            $minutes = ceil($seconds / 60);
            $errMsg = "Batas pengiriman kode OTP telah tercapai (maksimal 3 kali). Akses dibatasi selama 30 menit. Silakan tunggu {$minutes} menit ({$seconds} detik) sebelum meminta kode kembali.";
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $errMsg,
                    'retry_after' => $seconds,
                    'locked' => true,
                ], 429)->header('Retry-After', $seconds);
            }
            return back()->with('error', $errMsg);
        }

        $user = null;
        if ($nip) {
            $user = Pengguna::where('nip', $nip)->first();
            if (!$user) {
                $pegawai = \App\Models\PegawaiSimpeg::where('nip', $nip)->first();
                if ($pegawai) {
                    app(\App\Services\PenggunaProvisioningService::class)->provision($pegawai);
                    $user = Pengguna::where('nip', $nip)->first();
                }
            }
        } else {
            $user = Pengguna::whereRaw('LOWER(email) = ?', [$email])->first();
        }

        if (!$user || $user->status !== 'aktif') {
            $msg = 'Jika NIP dan email sesuai dengan data kami, kode OTP telah dikirim ke email tersebut.';
            if ($request->wantsJson()) {
                return response()->json(['message' => $msg]);
            }
            return back()->with('error', 'Data NIP tidak ditemukan atau akun Anda tidak aktif.');
        }

        // Jika akun belum memiliki email (data awal dari SIMPEG belum ada email)
        if (empty($user->email)) {
            // Cek apakah email sudah terpakai oleh akun lain yang sudah aktif
            $emailTerpakaiAktif = Pengguna::whereRaw('LOWER(email) = ?', [$email])
                ->where('pengguna_id', '!=', $user->pengguna_id)
                ->whereNotNull('kata_sandi_diatur_pada')
                ->exists();

            if ($emailTerpakaiAktif) {
                $errMsg = 'Email ini sudah terdaftar pada akun lain yang sudah aktif.';
                if ($request->wantsJson()) {
                    return response()->json(['message' => $errMsg], 422);
                }
                return back()->with('error', $errMsg);
            }

            // Bersihkan email jika terpasang di akun lain yang belum diaktivasi (misal sesi testing)
            Pengguna::whereRaw('LOWER(email) = ?', [$email])
                ->where('pengguna_id', '!=', $user->pengguna_id)
                ->whereNull('kata_sandi_diatur_pada')
                ->update(['email' => null]);

            \App\Models\PegawaiSimpeg::whereRaw('LOWER(email) = ?', [$email])
                ->where('nip', '!=', $user->nip)
                ->update(['email' => null]);

            // Simpan email ke tabel pengguna
            $user->email = $email;
            $user->save();

            // Sinkronkan juga ke tabel pegawai_simpegs
            \App\Models\PegawaiSimpeg::where('nip', $user->nip)
                ->update(['email' => $email]);
        } elseif (strtolower(trim($user->email)) !== $email) {
            $errMsg = 'Alamat email tidak cocok dengan data akun Anda.';
            if ($request->wantsJson()) {
                return response()->json(['message' => 'Jika NIP dan email sesuai dengan data kami, kode OTP telah dikirim ke email tersebut.']);
            }
            return back()->with('error', $errMsg);
        }

        /*
         * Nonaktifkan verification reset_password
         * sebelumnya yang masih active.
         */
        Verification::where('user_id', $user->pengguna_id)
            ->where('type', 'reset_password')
            ->where('status', 'active')
            ->update([
                'status' => 'invalid',
            ]);

        /*
         * Generate OTP 6 digit.
         */
        $otp = (string) random_int(100000, 999999);

        /*
         * Buat verification baru.
         */
        $verification = Verification::create([
            'user_id'   => $user->pengguna_id,
            'unique_id' => (string) Str::uuid(),
            'otp'       => Hash::make($otp),
            'type'      => 'reset_password',
            'send_via'  => 'email',
            'resent'    => 0,
            'attempts'  => 0,
            'status'    => 'active',
            'expires_at' => now()->addMinutes(5),
        ]);

        /*
         * Kirim email menggunakan Queue.
         */
        Mail::to($user->email)
            ->queue(new ForgotPasswordOtpMail($otp));

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Kode OTP telah dikirim ke email Anda.',
                'unique_id' => $verification->unique_id,
            ]);
        }

        return redirect()->route(
            'forgot-password.verify',
            $verification->unique_id
        )->with(
            'success',
            'Kode OTP telah dikirim ke email Anda.'
        );
    }

    /**
     * Halaman input OTP.
     */
    public function showVerify(string $uniqueId)
    {
        $verification = Verification::where('unique_id', $uniqueId)
            ->where('type', 'reset_password')
            ->where('status', 'active')
            ->first();

        if (!$verification) {
            abort(404);
        }

        /*
         * Jika OTP sudah expired, verification dibuat invalid.
         */
        if (
            $verification->expires_at &&
            now()->greaterThan($verification->expires_at)
        ) {
            $verification->update([
                'status' => 'invalid',
            ]);

            return redirect()
                ->route('forgot-password')
                ->with(
                    'error',
                    'OTP sudah kedaluwarsa. Silakan meminta OTP baru.'
                );
        }

        return view(
            'auth.verify-forgot-password',
            compact('verification')
        );
    }

    /**
     * Validasi OTP.
     */
    public function verifyOtp(Request $request, ?string $uniqueId = null)
    {
        $targetUniqueId = $uniqueId ?: $request->input('unique_id');

        $request->validate([
            'otp' => [
                'required',
                'digits:6',
            ],
        ]);

        $verification = Verification::with('user')
            ->where('unique_id', $targetUniqueId)
            ->where('type', 'reset_password')
            ->first();

        $userNip = $verification?->user?->nip ?? ($request->filled('nip') ? trim($request->nip) : null);
        $identifier = $userNip ?: $request->ip();
        $verifyLockKey = 'otp_verify_lockout:' . strtolower($identifier);

        if (RateLimiter::tooManyAttempts($verifyLockKey, 3)) {
            $seconds = RateLimiter::availableIn($verifyLockKey);
            $minutes = ceil($seconds / 60);
            $errMsg = "Terlalu banyak percobaan OTP yang salah (3 kali). Akses dibatasi selama 30 menit demi keamanan. Silakan tunggu {$minutes} menit ({$seconds} detik) sebelum mencoba kembali.";
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $errMsg,
                    'retry_after' => $seconds,
                    'remaining_attempts' => 0,
                    'locked' => true,
                ], 429)->header('Retry-After', $seconds);
            }
            return back()->with('error', $errMsg);
        }

        if (!$verification) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'Sesi verifikasi tidak valid atau tidak ditemukan.'], 404);
            }
            abort(404);
        }

        if ($verification->status === 'used') {
            $errMsg = 'Sesi verifikasi ini sudah selesai digunakan. Silakan mulai kembali dari awal.';
            if ($request->wantsJson()) {
                return response()->json(['message' => $errMsg], 422);
            }
            return back()->with('error', $errMsg);
        }

        /*
         * Cek expiration.
         */
        if (
            $verification->expires_at &&
            now()->greaterThan($verification->expires_at)
        ) {
            $verification->update([
                'status' => 'invalid',
            ]);

            $errMsg = 'OTP sudah kedaluwarsa. Silakan meminta OTP baru.';
            if ($request->wantsJson()) {
                return response()->json(['message' => $errMsg], 422);
            }
            return back()->with('error', $errMsg);
        }

        /*
         * Batasi jumlah percobaan OTP (maks 3).
         */
        if ($verification->attempts >= 3) {
            $verification->update([
                'status' => 'invalid',
            ]);
            while (RateLimiter::attempts($verifyLockKey) < 3) {
                RateLimiter::hit($verifyLockKey, 1800);
            }
            $seconds = RateLimiter::availableIn($verifyLockKey);
            $minutes = ceil($seconds / 60);
            $errMsg = "Anda telah mencapai batas 3 kali salah memasukkan kode OTP. Akses dibatasi selama 30 menit demi keamanan. Silakan tunggu {$minutes} menit ({$seconds} detik).";
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $errMsg,
                    'retry_after' => $seconds,
                    'remaining_attempts' => 0,
                    'locked' => true,
                ], 429)->header('Retry-After', $seconds);
            }
            return back()->with('error', $errMsg);
        }

        /*
         * Tambahkan jumlah attempts.
         */
        $verification->increment('attempts');

        /*
         * Validasi OTP via Hash::check.
         */
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
                $errMsg = "Kode OTP salah. Anda telah 3 kali salah memasukkan kode OTP. Akses dibatasi selama 30 menit demi keamanan. Silakan coba kembali setelah 30 menit.";
                if ($request->wantsJson()) {
                    return response()->json([
                        'message' => $errMsg,
                        'retry_after' => $seconds,
                        'remaining_attempts' => 0,
                        'locked' => true,
                    ], 429)->header('Retry-After', $seconds);
                }
                return back()->with('error', $errMsg);
            }

            $errMsg = "Kode OTP tidak valid. Sisa percobaan: {$sisa} kali.";
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $errMsg,
                    'remaining_attempts' => $sisa,
                ], 422);
            }
            return back()->with('error', $errMsg);
        }

        /*
         * OTP benar -> status = valid.
         */
        $verification->update([
            'status' => 'valid',
        ]);
        RateLimiter::clear($verifyLockKey);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Kode OTP berhasil diverifikasi.',
                'unique_id' => $verification->unique_id,
            ]);
        }

        return redirect()->route(
            'forgot-password.reset',
            $verification->unique_id
        );
    }

    /**
     * Halaman reset password.
     */
    public function showResetPassword(string $uniqueId)
    {
        $verification = Verification::where('unique_id', $uniqueId)
            ->where('type', 'reset_password')
            ->where('status', 'valid')
            ->first();

        if (!$verification) {
            abort(403);
        }

        return view(
            'auth.reset-password',
            compact('verification')
        );
    }

    /**
     * Update password baru.
     */
    public function resetPassword(Request $request, ?string $uniqueId = null)
    {
        $targetUniqueId = $uniqueId ?: $request->input('unique_id');

        $verification = Verification::where('unique_id', $targetUniqueId)
            ->where('type', 'reset_password')
            ->where('status', 'valid')
            ->first();

        if (!$verification) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'Sesi reset kata sandi tidak valid atau telah kedaluwarsa.'], 403);
            }
            abort(403);
        }

        $user = Pengguna::find($verification->user_id);
        if (!$user) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'Pengguna tidak ditemukan.'], 404);
            }
            abort(404);
        }

        $passwordRule = Pengguna::aturanKataSandi($user->nip);

        $request->validate([
            'password' => $passwordRule,
        ]);

        /*
         * Update password pengguna & tandai aktivasi.
         */
        $user->setKataSandiPengguna($request->password)->save();

        /*
         * Verification hanya boleh digunakan sekali (status = used).
         */
        $verification->update([
            'status' => 'used',
        ]);

        // Revoke token lama & reset lockout login
        $user->tokens()->delete();
        RateLimiter::clear('login_failed:' . strtolower($user->nip) . '|' . $request->ip());
        RateLimiter::clear('otp_verify_lockout:' . strtolower($user->nip));
        RateLimiter::clear('otp_resend_lockout:' . strtolower($user->nip));

        $successMsg = 'Password berhasil diubah. Silakan login kembali.';

        if ($request->wantsJson()) {
            return response()->json(['message' => $successMsg]);
        }

        return redirect()
            ->route('login')
            ->with('success', $successMsg);
    }

    /**
     * Resend OTP.
     */
    public function resendOtp(Request $request, ?string $uniqueId = null)
    {
        $targetUniqueId = $uniqueId ?: $request->input('unique_id');
        $nip = $request->input('nip');

        $verification = null;
        if ($targetUniqueId) {
            $verification = Verification::with('user')->where('unique_id', $targetUniqueId)
                ->where('type', 'reset_password')
                ->first();
        }

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

        if (RateLimiter::tooManyAttempts($verifyLockKey, 3)) {
            $seconds = RateLimiter::availableIn($verifyLockKey);
            $minutes = ceil($seconds / 60);
            $errMsg = "Akses sedang dibatasi selama 30 menit karena 3 kali salah memasukkan kode OTP. Silakan tunggu {$minutes} menit ({$seconds} detik).";
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $errMsg,
                    'retry_after' => $seconds,
                    'locked' => true,
                ], 429)->header('Retry-After', $seconds);
            }
            return back()->with('error', $errMsg);
        }

        if (RateLimiter::tooManyAttempts($resendLockKey, 3)) {
            $seconds = RateLimiter::availableIn($resendLockKey);
            $minutes = ceil($seconds / 60);
            $errMsg = "Batas pengiriman ulang kode OTP telah tercapai (maksimal 3 kali). Akses dibatasi selama 30 menit. Silakan tunggu {$minutes} menit ({$seconds} detik) sebelum mencoba kembali.";
            if ($request->wantsJson()) {
                return response()->json([
                    'message' => $errMsg,
                    'retry_after' => $seconds,
                    'locked' => true,
                ], 429)->header('Retry-After', $seconds);
            }
            return back()->with('error', $errMsg);
        }

        if (!$verification) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'Sesi verifikasi tidak ditemukan.'], 404);
            }
            return redirect()->route('forgot-password')->with('error', 'Sesi verifikasi tidak ditemukan.');
        }

        if ($verification->status === 'used') {
            $errMsg = 'Sesi verifikasi ini sudah selesai digunakan.';
            if ($request->wantsJson()) {
                return response()->json(['message' => $errMsg], 422);
            }
            return back()->with('error', $errMsg);
        }

        /*
         * Maksimal 3 kali resend.
         */
        if ($verification->resent >= 3) {
            while (RateLimiter::attempts($resendLockKey) < 3) {
                RateLimiter::hit($resendLockKey, 1800);
            }
            $seconds = RateLimiter::availableIn($resendLockKey);
            $minutes = ceil($seconds / 60);
            $errMsg = "Batas pengiriman ulang kode OTP telah tercapai (maksimal 3 kali). Akses dibatasi selama 30 menit demi keamanan. Silakan tunggu {$minutes} menit ({$seconds} detik).";
            if ($request->wantsJson()) {
                return response()->json([
                    'message'    => $errMsg,
                    'retry_after' => $seconds,
                    'resent'     => 3,
                    'max_resend' => 3,
                    'locked'     => true,
                ], 429)->header('Retry-After', $seconds);
            }
            return back()->with('error', $errMsg);
        }

        $user = Pengguna::find($verification->user_id);
        if (!$user || empty($user->email)) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'Akun pengguna atau alamat email tidak valid.'], 404);
            }
            abort(404);
        }

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
            'attempts'   => 0,
            'expires_at' => now()->addMinutes(5),
            'status'     => 'active',
        ]);

        RateLimiter::clear($verifyLockKey);

        Mail::to($user->email)
            ->queue(new ForgotPasswordOtpMail($otp));

        $msg = "Kode OTP baru telah dikirim ke email Anda. (Pengiriman ke-{$newResent} dari 3)";
        if ($request->wantsJson()) {
            return response()->json([
                'message'    => $msg,
                'unique_id'  => $verification->unique_id,
                'resent'     => $newResent,
                'max_resend' => 3,
            ]);
        }

        return back()->with('success', $msg);
    }
}
