<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Pengguna;
use App\Services\SimpegApiService;
use App\Services\OtpService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Mail\OtpMail;

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
     * Menggunakan NIP (atau Email) dan Password kustom yang telah dibuat
     */
    public function login(Request $request)
    {
        $request->validate([
            'nip' => 'required|string',
            'password' => 'required|string',
        ]);

        $identifier = trim($request->nip);

        $pengguna = Pengguna::where('nip', $identifier)
            ->orWhere('email', $identifier)
            ->first();

        // Hash::check selalu dijalankan agar waktu respons tidak membedakan NIP ada/tidak ada.
        $dummy = Cache::rememberForever('auth:dummy_hash', fn () => Hash::make(Str::random(16)));
        $cocok = Hash::check($request->password, $pengguna?->kata_sandi_hash ?? $dummy);

        if (!$pengguna || !$cocok || !$pengguna->sudah_aktivasi) {
            return response()->json([
                'message' => 'NIP atau kata sandi salah. Jika ini pertama kali Anda masuk atau Anda lupa kata sandi, gunakan "Lupa Kata Sandi".'
            ], 401);
        }

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

        $otp = $this->otp->terbitkan('ubah', (string) $pengguna->pengguna_id);
        if (!$otp) {
            return response()->json([
                'message' => 'Silakan tunggu sebelum meminta kode OTP kembali.'
            ], 429);
        }

        try {
            Mail::to($pengguna->email)->send(new OtpMail($otp));
        } catch (\Throwable $e) {
            Log::error('Gagal kirim email OTP ubah password: ' . $e->getMessage());
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

        // Validasi OTP
        if (!$this->otp->verifikasi('ubah', (string) $pengguna->pengguna_id, $request->otp)) {
            return response()->json([
                'message' => 'Kode OTP tidak valid atau sudah kedaluwarsa.'
            ], 422);
        }

        if (Hash::check($request->password_baru, $pengguna->kata_sandi_hash)) {
            return response()->json([
                'message' => 'Password baru tidak boleh sama dengan password sebelumnya.'
            ], 400);
        }

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
            'nip' => 'required|string',
            'email' => 'required|email',
        ]);

        $pesan = 'Jika NIP dan email sesuai dengan data kami, kode OTP telah dikirim ke email tersebut.';

        $pengguna = Pengguna::where('nip', trim($request->nip))->first();

        $layak = $pengguna
            && $pengguna->status === 'aktif'
            && filled($pengguna->email)
            && strtolower(trim($pengguna->email)) === strtolower(trim($request->email));

        if ($layak) {
            $otp = $this->otp->terbitkan('reset', $pengguna->nip);   // null = masih cooldown
            if ($otp) {
                $email = $pengguna->email;
                dispatch(function () use ($email, $otp) {
                    try {
                        Mail::to($email)->send(new OtpMail($otp));
                    } catch (\Throwable $e) {
                        Log::error('Gagal kirim OTP reset: ' . $e->getMessage());
                    }
                })->afterResponse();   // waktu respons sama untuk kasus layak/tidak layak
            }
        }

        return response()->json(['message' => $pesan]);   // SELALU 200 dan pesan sama
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'nip' => 'required|string',
            'otp' => 'required|digits:6',
            'password_baru' => Pengguna::aturanKataSandi($request->nip),
        ]);

        $pengguna = Pengguna::where('nip', $request->nip)->first();

        // Panggil verifikasi walau $pengguna null agar perilaku timing sama
        $otpValid = $this->otp->verifikasi('reset', $request->nip, $request->otp);

        if (!$pengguna || $pengguna->status !== 'aktif' || !$otpValid) {
            return response()->json([
                'message' => 'Kode OTP tidak valid atau sudah kedaluwarsa.'
            ], 422);
        }

        $pengguna->setKataSandiPengguna($request->password_baru)->save();
        $pengguna->tokens()->delete();

        return response()->json([
            'message' => 'Kata sandi berhasil dibuat. Silakan masuk dengan kata sandi baru Anda.'
        ]);
    }
}
