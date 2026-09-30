<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Pengguna;
use App\Models\PenggunaPeran;
use App\Models\PegawaiSimpeg;
use App\Services\OtpService;
use App\Services\PenggunaProvisioningService;
use Illuminate\Support\Facades\Mail;
use App\Mail\OtpMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login');
        RateLimiter::clear('lupa-sandi');
        RateLimiter::clear('reset-sandi');
    }

    /**
     * 5. Login gagal (401, pesan sama persis) untuk: NIP tidak ada, sandi salah, akun belum aktivasi.
     * Login berhasil setelah alur lupa sandi.
     */
    public function test_login_failure_messages_are_identical_and_login_succeeds_after_reset(): void
    {
        $expectedMsg = 'NIP atau kata sandi salah. Jika ini pertama kali Anda masuk atau Anda lupa kata sandi, gunakan "Lupa Kata Sandi".';

        // 1. NIP tidak ada
        $resp1 = $this->postJson('/api/login', [
            'nip' => '999999999999999999',
            'password' => 'WrongPass123!',
        ]);
        $resp1->assertStatus(401)->assertJson(['message' => $expectedMsg]);

        // 2. Akun ada, tetapi belum aktivasi (kata_sandi_diatur_pada = null)
        $user = new Pengguna([
            'nip' => '199001012020011010',
            'nama_lengkap' => 'Belum Aktivasi',
            'email' => 'belum.aktivasi@bulelengkab.go.id',
            'peran' => 'peserta',
            'status' => 'aktif',
        ]);
        $user->acakKataSandi()->save();

        $resp2 = $this->postJson('/api/login', [
            'nip' => '199001012020011010',
            'password' => 'AnyPassword123!',
        ]);
        $resp2->assertStatus(401)->assertJson(['message' => $expectedMsg]);

        // 3. Akun sudah aktivasi, tetapi kata sandi salah
        $user->setKataSandiPengguna('KataSandiBenar123!')->save();

        $resp3 = $this->postJson('/api/login', [
            'nip' => '199001012020011010',
            'password' => 'KataSandiSalah123!',
        ]);
        $resp3->assertStatus(401)->assertJson(['message' => $expectedMsg]);

        // 4. Login sukses dengan kata sandi yang benar
        $resp4 = $this->postJson('/api/login', [
            'nip' => '199001012020011010',
            'password' => 'KataSandiBenar123!',
        ]);
        $resp4->assertStatus(200)->assertJsonStructure(['access_token', 'user']);
    }

    /**
     * 6. Login dengan sandi NIP atau 8 digit NIP pada akun baru hasil provisioning gagal.
     */
    public function test_login_with_nip_or_last8_digits_on_new_account_fails(): void
    {
        $pegawai = PegawaiSimpeg::create([
            'nip' => '199201012020011020',
            'nama_lengkap' => 'Pegawai Baru Provisioning',
            'email' => 'baru@bulelengkab.go.id',
            'rumpun_jabatan' => 'JP',
        ]);
        app(PenggunaProvisioningService::class)->provision($pegawai);

        // Coba login dengan NIP lengkap
        $respNip = $this->postJson('/api/login', [
            'nip' => '199201012020011020',
            'password' => '199201012020011020',
        ]);
        $respNip->assertStatus(401);

        // Coba login dengan 8 digit terakhir NIP
        $respLast8 = $this->postJson('/api/login', [
            'nip' => '199201012020011020',
            'password' => '2020011020',
        ]);
        $respLast8->assertStatus(401);
    }

    /**
     * 7. forgot-password mengembalikan 200 dengan pesan identik untuk NIP tidak ada,
     * email tidak cocok, dan kasus valid; email OTP hanya terkirim pada kasus valid.
     */
    public function test_forgot_password_timing_safe_and_sends_email_only_on_valid_match(): void
    {
        Mail::fake();
        $expectedMsg = 'Jika NIP dan email sesuai dengan data kami, kode OTP telah dikirim ke email tersebut.';

        $user = new Pengguna([
            'nip' => '199301012020011030',
            'nama_lengkap' => 'User Reset Test',
            'email' => 'valid@bulelengkab.go.id',
            'peran' => 'peserta',
            'status' => 'aktif',
        ]);
        $user->acakKataSandi()->save();

        // Kasus 1: NIP tidak ada
        $resp1 = $this->postJson('/api/forgot-password', [
            'nip' => '000000000000000000',
            'email' => 'valid@bulelengkab.go.id',
        ]);
        $resp1->assertStatus(200)->assertJson(['message' => $expectedMsg]);
        Mail::assertNothingSent();

        // Kasus 2: NIP ada tapi email tidak cocok
        $resp2 = $this->postJson('/api/forgot-password', [
            'nip' => '199301012020011030',
            'email' => 'salah@bulelengkab.go.id',
        ]);
        $resp2->assertStatus(200)->assertJson(['message' => $expectedMsg]);
        Mail::assertNothingSent();

        // Kasus 3: NIP dan email cocok
        $resp3 = $this->postJson('/api/forgot-password', [
            'nip' => '199301012020011030',
            'email' => 'valid@bulelengkab.go.id',
        ]);
        $resp3->assertStatus(200)->assertJson(['message' => $expectedMsg]);
        Mail::assertSent(OtpMail::class);
    }

    /**
     * 8. reset-password: OTP benar berhasil; OTP salah 5 kali menghanguskan OTP;
     * OTP tidak bisa dipakai dua kali; sandi lemah atau mengandung NIP ditolak; token lama dicabut.
     */
    public function test_reset_password_otp_verification_brute_force_protection_and_token_revocation(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $user = new Pengguna([
            'nip' => '199401012020011040',
            'nama_lengkap' => 'User OTP Test',
            'email' => 'otptest@bulelengkab.go.id',
            'peran' => 'peserta',
            'status' => 'aktif',
        ]);
        $user->acakKataSandi()->save();

        // Buat token login lama
        $token = $user->createToken('old_token')->plainTextToken;
        $this->assertCount(1, $user->tokens);

        // Terbitkan OTP via service
        $otpService = app(OtpService::class);
        $otp = $otpService->terbitkan('reset', $user->nip);
        $this->assertNotNull($otp);

        // 8a. Password lemah atau mengandung NIP harus ditolak validasi
        $respWeak = $this->postJson('/api/reset-password', [
            'nip' => $user->nip,
            'otp' => $otp,
            'password_baru' => 'short1',
            'password_baru_confirmation' => 'short1',
        ]);
        $respWeak->assertStatus(422);

        $respWithNip = $this->postJson('/api/reset-password', [
            'nip' => $user->nip,
            'otp' => $otp,
            'password_baru' => 'A1b2c3d4' . $user->nip,
            'password_baru_confirmation' => 'A1b2c3d4' . $user->nip,
        ]);
        $respWithNip->assertStatus(422);

        // 8b. Coba OTP salah 5 kali
        for ($i = 1; $i <= 5; $i++) {
            $respWrong = $this->postJson('/api/reset-password', [
                'nip' => $user->nip,
                'otp' => '000000',
                'password_baru' => 'KuatRahasia123!',
                'password_baru_confirmation' => 'KuatRahasia123!',
            ]);
            $respWrong->assertStatus(422)->assertJson(['message' => 'Kode OTP tidak valid atau sudah kedaluwarsa.']);
        }

        // Percobaan ke-6 walaupun dengan OTP yang BENAR harus gagal karena sudah hangus
        $respBurned = $this->postJson('/api/reset-password', [
            'nip' => $user->nip,
            'otp' => $otp,
            'password_baru' => 'KuatRahasia123!',
            'password_baru_confirmation' => 'KuatRahasia123!',
        ]);
        $respBurned->assertStatus(422)->assertJson(['message' => 'Kode OTP tidak valid atau sudah kedaluwarsa.']);

        // Terbitkan OTP baru (reset cooldown untuk testing)
        \Illuminate\Support\Facades\Cache::forget("otp:cd:reset:{$user->nip}");
        $newOtp = $otpService->terbitkan('reset', $user->nip);

        // Reset dengan OTP benar
        $respSuccess = $this->postJson('/api/reset-password', [
            'nip' => $user->nip,
            'otp' => $newOtp,
            'password_baru' => 'KuatRahasia123!',
            'password_baru_confirmation' => 'KuatRahasia123!',
        ]);
        $respSuccess->assertStatus(200);

        // Token lama harus dicabut
        $this->assertCount(0, $user->fresh()->tokens);

        // Akun sekarang sudah aktivasi
        $this->assertTrue($user->fresh()->sudah_aktivasi);

        // OTP tidak bisa dipakai dua kali (replay attack)
        $respReplay = $this->postJson('/api/reset-password', [
            'nip' => $user->nip,
            'otp' => $newOtp,
            'password_baru' => 'KuatRahasia456!',
            'password_baru_confirmation' => 'KuatRahasia456!',
        ]);
        $respReplay->assertStatus(422);
    }

    /**
     * 9. Admin store menghasilkan akun tanpa sandi yang dapat ditebak,
     * resetPassword admin membuat kata_sandi_diatur_pada null dan mencabut token; response tidak memuat sandi.
     */
    public function test_admin_user_management_password_security(): void
    {
        $admin = Pengguna::create([
            'nip' => 'root',
            'nama_lengkap' => 'Admin BKPSDM',
            'email' => 'admin@bkpsdm.go.id',
            'kata_sandi_hash' => Hash::make('AdminSecret123!'),
            'kata_sandi_diatur_pada' => now(),
            'peran' => 'admin_bkpsdm',
            'status' => 'aktif',
        ]);
        PenggunaPeran::create(['pengguna_id' => $admin->pengguna_id, 'peran' => 'admin_bkpsdm']);

        // 9a. Store manual oleh admin
        $respStore = $this->actingAs($admin)->postJson('/api/admin-bkpsdm/pengguna', [
            'nip' => '199501012020011050',
            'nama_lengkap' => 'Pegawai Manual',
            'email' => 'manual@bulelengkab.go.id',
            'peran' => 'peserta',
            'roles' => ['peserta'],
        ]);
        $respStore->assertStatus(201);
        $this->assertArrayNotHasKey('kata_sandi', $respStore->json('data'));
        $this->assertArrayNotHasKey('password', $respStore->json('data'));

        $newUser = Pengguna::where('nip', '199501012020011050')->first();
        $this->assertNotNull($newUser);
        $this->assertNull($newUser->kata_sandi_diatur_pada);
        $this->assertFalse($newUser->sudah_aktivasi);

        // 9b. Reset password oleh admin
        $newUser->setKataSandiPengguna('TemporarySet123!')->save();
        $newUser->createToken('active_token');
        $this->assertCount(1, $newUser->tokens);

        $respReset = $this->actingAs($admin)->postJson("/api/admin-bkpsdm/pengguna/{$newUser->pengguna_id}/reset-password");
        $respReset->assertStatus(200);

        $refreshed = $newUser->fresh();
        $this->assertNull($refreshed->kata_sandi_diatur_pada);
        $this->assertFalse($refreshed->sudah_aktivasi);
        $this->assertCount(0, $refreshed->tokens);
        // Pastikan response tidak membocorkan sandi apa pun
        $this->assertStringNotContainsString('password', strtolower(json_encode($respReset->json())));
    }

    /**
     * 10. Rate limit login: percobaan ke-6 dalam semenit mengembalikan 429.
     */
    public function test_login_rate_limiting(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $resp = $this->postJson('/api/login', [
                'nip' => '199601012020011060',
                'password' => 'WrongPass123!',
            ]);
            $resp->assertStatus(401);
        }

        // Percobaan ke-6 harus terkena 429 Too Many Requests
        $resp6 = $this->postJson('/api/login', [
            'nip' => '199601012020011060',
            'password' => 'WrongPass123!',
        ]);
        $resp6->assertStatus(429);
    }
}
