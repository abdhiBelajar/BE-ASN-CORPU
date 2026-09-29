<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Pengguna;
use App\Services\SimpegApiService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use App\Mail\OtpMail;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    protected $simpegApi;

    public function __construct(SimpegApiService $simpegApi)
    {
        $this->simpegApi = $simpegApi;
    }

    /**
     * Autentikasi Pengguna (Login)
     * Menggunakan NIP (atau Email) dan Password kustom yang telah dibuat
     */
    public function login(Request $request)
    {
        $request->validate([
            'nip' => 'required|string',
            'password' => 'required|string',
        ]);

        $identifier = trim($request->nip);
        $password = $request->password;

        // Cari akun berdasarkan NIP atau Email
        $pengguna = Pengguna::where('nip', $identifier)
            ->orWhere('email', $identifier)
            ->first();

        if (!$pengguna) {
            return response()->json([
                'message' => 'Akun dengan NIP tersebut belum terdaftar. Pastikan akun Anda sudah terdaftar di SIMPEG atau hubungi administrator BKPSDM.'
            ], 404);
        }

        // Cek kecocokan kata sandi
        if (!Hash::check($password, $pengguna->kata_sandi_hash)) {
            return response()->json([
                'message' => 'Kata sandi salah. Silakan periksa kembali.'
            ], 401);
        }

        // Cek apakah akun aktif
        if ($pengguna->status !== 'aktif') {
            return response()->json([
                'message' => 'Akun Anda sedang dinonaktifkan. Hubungi administrator BKPSDM.'
            ], 403);
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

        $otp = (string) rand(100000, 999999);
        \Illuminate\Support\Facades\Cache::put('otp_change_' . $pengguna->pengguna_id, $otp, now()->addMinutes(10));

        try {
            \Illuminate\Support\Facades\Mail::to($pengguna->email)->send(new \App\Mail\OtpMail($otp));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Gagal kirim email OTP ubah password: ' . $e->getMessage());
            return response()->json([
                'message' => 'Gagal mengirim email OTP. Silakan coba beberapa saat lagi.'
            ], 500);
        }

        return response()->json([
            'message' => 'Kode OTP telah dikirim ke email terdaftar Anda.'
        ]);
    }

    public function changePasswordVerify(Request $request)
    {
        $request->validate([
            'otp' => 'required|string',
            'password_sebelumnya' => 'required|string',
            'password_baru' => 'required|string|min:8|confirmed',
        ]);

        $pengguna = $request->user();

        // Validasi password lama
        if (!Hash::check($request->password_sebelumnya, $pengguna->kata_sandi_hash)) {
            return response()->json([
                'message' => 'Password sebelumnya tidak sesuai.'
            ], 400);
        }

        // Validasi OTP
        $cachedOtp = \Illuminate\Support\Facades\Cache::get('otp_change_' . $pengguna->pengguna_id);
        if (!$cachedOtp || $cachedOtp !== $request->otp) {
            return response()->json([
                'message' => 'Kode OTP tidak valid atau sudah kedaluwarsa.'
            ], 400);
        }

        if (Hash::check($request->password_baru, $pengguna->kata_sandi_hash)) {
            return response()->json([
                'message' => 'Password baru tidak boleh sama dengan password sebelumnya.'
            ], 400);
        }

        $pengguna->update([
            'kata_sandi_hash' => Hash::make($request->password_baru)
        ]);

        \Illuminate\Support\Facades\Cache::forget('otp_change_' . $pengguna->pengguna_id);

        return response()->json([
            'message' => 'Password berhasil diubah.'
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'nip' => 'required|string',
            'email' => 'required|email',
        ]);

        $pengguna = Pengguna::where('nip', $request->nip)->first();

        if (!$pengguna) {
            return response()->json([
                'message' => 'NIP tidak ditemukan.'
            ], 404);
        }

        // Jangan izinkan binding email baru secara sepihak jika email kosong
        if (empty($pengguna->email)) {
            return response()->json([
                'message' => 'Akun belum memiliki email resmi terdaftar. Silakan hubungi administrator BKPSDM.'
            ], 400);
        }

        if (strtolower(trim($pengguna->email)) !== strtolower(trim($request->email))) {
            return response()->json([
                'message' => 'Email tidak cocok dengan data pengguna yang terdaftar.'
            ], 400);
        }

        $otp = (string) rand(100000, 999999);
        \Illuminate\Support\Facades\Cache::put('otp_reset_' . $pengguna->nip, $otp, now()->addMinutes(10));

        try {
            \Illuminate\Support\Facades\Mail::to($pengguna->email)->send(new \App\Mail\OtpMail($otp));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Gagal kirim email OTP reset password: ' . $e->getMessage());
            return response()->json([
                'message' => 'Gagal mengirim email OTP. Silakan coba beberapa saat lagi.'
            ], 500);
        }

        return response()->json([
            'message' => 'Kode OTP telah dikirim ke email Anda.'
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'nip' => 'required|string',
            'otp' => 'required|string',
            'password_baru' => 'required|string|min:8|confirmed',
        ]);

        $pengguna = Pengguna::where('nip', $request->nip)->first();

        if (!$pengguna) {
            return response()->json([
                'message' => 'NIP tidak ditemukan.'
            ], 404);
        }

        $cachedOtp = \Illuminate\Support\Facades\Cache::get('otp_reset_' . $pengguna->nip);
        if (!$cachedOtp || $cachedOtp !== $request->otp) {
            return response()->json([
                'message' => 'Kode OTP tidak valid atau sudah kedaluwarsa.'
            ], 400);
        }

        $pengguna->update([
            'kata_sandi_hash' => Hash::make($request->password_baru)
        ]);

        \Illuminate\Support\Facades\Cache::forget('otp_reset_' . $pengguna->nip);

        return response()->json([
            'message' => 'Password berhasil di-reset! Silakan login dengan password baru Anda.'
        ]);
    }
}
