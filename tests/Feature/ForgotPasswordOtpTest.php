<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Pengguna;
use App\Models\Verification;
use App\Mail\ForgotPasswordOtpMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ForgotPasswordOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_otp_generates_hash_and_queues_email(): void
    {
        Mail::fake();

        $user = new Pengguna([
            'nip' => '199501012020011050',
            'nama_lengkap' => 'Pegawai Reset OTP',
            'email' => 'pegawai.otp@bulelengkab.go.id',
            'peran' => 'peserta',
            'status' => 'aktif',
        ]);
        $user->acakKataSandi()->save();
        $this->assertFalse($user->sudah_aktivasi);

        $response = $this->postJson('/api/forgot-password', [
            'nip' => $user->nip,
            'email' => $user->email,
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('unique_id'));

        $verification = Verification::where('user_id', $user->pengguna_id)
            ->where('type', 'reset_password')
            ->where('status', 'active')
            ->first();

        $this->assertNotNull($verification);
        $this->assertEquals(0, $verification->attempts);
        $this->assertEquals(0, $verification->resent);
        $this->assertNotEquals(6, strlen($verification->otp)); // Pastikan ter-hash (bukan plaintext 6 digit)

        Mail::assertQueued(ForgotPasswordOtpMail::class);
    }

    public function test_verify_otp_validates_and_marks_status_valid(): void
    {
        $user = new Pengguna([
            'nip' => '199501012020011051',
            'nama_lengkap' => 'Pegawai Verify OTP',
            'email' => 'verify.otp@bulelengkab.go.id',
            'peran' => 'peserta',
            'status' => 'aktif',
        ]);
        $user->acakKataSandi()->save();

        $plainOtp = '654321';
        $verification = Verification::create([
            'user_id'   => $user->pengguna_id,
            'unique_id' => (string) Str::uuid(),
            'otp'       => Hash::make($plainOtp),
            'type'      => 'reset_password',
            'send_via'  => 'email',
            'resent'    => 0,
            'attempts'  => 0,
            'status'    => 'active',
            'expires_at' => now()->addMinutes(5),
        ]);

        // Coba OTP salah
        $wrongResp = $this->postJson('/api/forgot-password/verify', [
            'unique_id' => $verification->unique_id,
            'otp' => '000000',
        ]);
        $wrongResp->assertStatus(422);
        $this->assertEquals(1, $verification->fresh()->attempts);
        $this->assertEquals('active', $verification->fresh()->status);

        // Verifikasi dengan OTP benar
        $correctResp = $this->postJson('/api/forgot-password/verify', [
            'unique_id' => $verification->unique_id,
            'otp' => $plainOtp,
        ]);
        $correctResp->assertStatus(200);
        $this->assertEquals('valid', $verification->fresh()->status);
    }

    public function test_reset_password_activates_account_and_marks_verification_used(): void
    {
        $user = new Pengguna([
            'nip' => '199501012020011052',
            'nama_lengkap' => 'Pegawai Sandi Baru',
            'email' => 'sandibaru@bulelengkab.go.id',
            'peran' => 'peserta',
            'status' => 'aktif',
        ]);
        $user->acakKataSandi()->save();
        $this->assertFalse($user->sudah_aktivasi);

        $verification = Verification::create([
            'user_id'   => $user->pengguna_id,
            'unique_id' => (string) Str::uuid(),
            'otp'       => Hash::make('123456'),
            'type'      => 'reset_password',
            'send_via'  => 'email',
            'status'    => 'valid',
            'expires_at' => now()->addMinutes(5),
        ]);

        $newPassword = 'PasswordBaruKuat123#';
        $resetResp = $this->postJson('/api/reset-password', [
            'unique_id' => $verification->unique_id,
            'password_baru' => $newPassword,
            'password_baru_confirmation' => $newPassword,
        ]);

        $resetResp->assertStatus(200);
        $this->assertEquals('used', $verification->fresh()->status);
        $this->assertTrue($user->fresh()->sudah_aktivasi);

        // Login dengan password baru harus BERHASIL
        $loginResp = $this->postJson('/api/login', [
            'nip' => $user->nip,
            'password' => $newPassword,
        ]);
        $loginResp->assertStatus(200);
    }

    public function test_resend_otp_enforces_limit_of_three_times(): void
    {
        Mail::fake();

        $user = new Pengguna([
            'nip' => '199501012020011053',
            'nama_lengkap' => 'Pegawai Resend OTP',
            'email' => 'resend.otp@bulelengkab.go.id',
            'peran' => 'peserta',
            'status' => 'aktif',
        ]);
        $user->acakKataSandi()->save();

        $verification = Verification::create([
            'user_id'   => $user->pengguna_id,
            'unique_id' => (string) Str::uuid(),
            'otp'       => Hash::make('111111'),
            'type'      => 'reset_password',
            'send_via'  => 'email',
            'resent'    => 2, // sudah 2 kali resend
            'attempts'  => 0,
            'status'    => 'active',
            'expires_at' => now()->addMinutes(5),
        ]);

        // Resend ke-3 (diizinkan, batas maksimal tercapai)
        $resp3 = $this->postJson('/api/forgot-password/resend', [
            'unique_id' => $verification->unique_id,
        ]);
        $resp3->assertStatus(200);
        $this->assertEquals(3, $verification->fresh()->resent);

        // Resend ke-4 (harus DITOLAK 429 karena batas 3x dan dikunci 30 menit)
        $resp4 = $this->postJson('/api/forgot-password/resend', [
            'unique_id' => $verification->unique_id,
        ]);
        $resp4->assertStatus(429);
        $this->assertStringContainsString('Batas pengiriman ulang kode OTP telah tercapai', $resp4->json('message'));
        $this->assertTrue($resp4->json('locked'));
        $this->assertNotNull($resp4->json('retry_after'));

        // Akun lain yang belum mencapai batas resend dapat mengaktifkan kembali sesi yang invalid
        $userOther = new Pengguna([
            'nip' => '199501012020011088',
            'nama_lengkap' => 'Pegawai Resend Lain',
            'email' => 'resend.lain@bulelengkab.go.id',
            'peran' => 'peserta',
            'status' => 'aktif',
        ]);
        $userOther->acakKataSandi()->save();

        $verif2 = Verification::create([
            'user_id'   => $userOther->pengguna_id,
            'unique_id' => (string) Str::uuid(),
            'otp'       => Hash::make('222222'),
            'type'      => 'reset_password',
            'send_via'  => 'email',
            'resent'    => 1,
            'attempts'  => 3,
            'status'    => 'invalid',
            'expires_at' => now()->subMinute(),
        ]);

        $reactivateResp = $this->postJson('/api/forgot-password/resend', [
            'unique_id' => $verif2->unique_id,
        ]);
        $reactivateResp->assertStatus(200);
        $this->assertEquals('active', $verif2->fresh()->status);
        $this->assertEquals(0, $verif2->fresh()->attempts);
        $this->assertEquals(2, $verif2->fresh()->resent);
    }

    public function test_wrong_otp_three_times_locks_out_for_30_minutes(): void
    {
        $user = new Pengguna([
            'nip' => '199501012020011077',
            'nama_lengkap' => 'Pegawai Wrong OTP Lockout',
            'email' => 'wrong.lockout@bulelengkab.go.id',
            'peran' => 'peserta',
            'status' => 'aktif',
        ]);
        $user->acakKataSandi()->save();

        $plainOtp = '654321';
        $verification = Verification::create([
            'user_id'   => $user->pengguna_id,
            'unique_id' => (string) Str::uuid(),
            'otp'       => Hash::make($plainOtp),
            'type'      => 'reset_password',
            'send_via'  => 'email',
            'resent'    => 0,
            'attempts'  => 0,
            'status'    => 'active',
            'expires_at' => now()->addMinutes(5),
        ]);

        // Percobaan salah 1
        $r1 = $this->postJson('/api/forgot-password/verify', [
            'unique_id' => $verification->unique_id,
            'otp' => '000000',
        ]);
        $r1->assertStatus(422);
        $this->assertEquals(2, $r1->json('remaining_attempts'));

        // Percobaan salah 2
        $r2 = $this->postJson('/api/forgot-password/verify', [
            'unique_id' => $verification->unique_id,
            'otp' => '000000',
        ]);
        $r2->assertStatus(422);
        $this->assertEquals(1, $r2->json('remaining_attempts'));

        // Percobaan salah 3 -> Langsung 429 dan lock 30 menit
        $r3 = $this->postJson('/api/forgot-password/verify', [
            'unique_id' => $verification->unique_id,
            'otp' => '000000',
        ]);
        $r3->assertStatus(429);
        $this->assertTrue($r3->json('locked'));
        $this->assertEquals(0, $r3->json('remaining_attempts'));
        $this->assertGreaterThan(1700, $r3->json('retry_after'));
        $this->assertEquals('invalid', $verification->fresh()->status);

        // Percobaan ke-4 (bahkan dengan OTP benar) harus ditolak 429 karena sedang terkunci
        $r4 = $this->postJson('/api/forgot-password/verify', [
            'unique_id' => $verification->unique_id,
            'otp' => $plainOtp,
        ]);
        $r4->assertStatus(429);
        $this->assertTrue($r4->json('locked'));

        // Permintaan OTP baru juga diblokir selama masa lockout 30 menit
        $rRequest = $this->postJson('/api/forgot-password', [
            'nip' => $user->nip,
            'email' => $user->email,
        ]);
        $rRequest->assertStatus(429);
        $this->assertTrue($rRequest->json('locked'));
    }

    public function test_blade_views_and_web_routes(): void
    {
        $user = new Pengguna([
            'nip' => '199501012020011054',
            'nama_lengkap' => 'Pegawai Blade View',
            'email' => 'blade.otp@bulelengkab.go.id',
            'peran' => 'peserta',
            'status' => 'aktif',
        ]);
        $user->acakKataSandi()->save();

        // 1. GET /forgot-password
        $resp1 = $this->get('/forgot-password');
        $resp1->assertStatus(200);
        $resp1->assertSee('Lupa Kata Sandi');

        // 2. GET /forgot-password/verify/{uniqueId}
        $verification = Verification::create([
            'user_id'   => $user->pengguna_id,
            'unique_id' => (string) Str::uuid(),
            'otp'       => Hash::make('123456'),
            'type'      => 'reset_password',
            'send_via'  => 'email',
            'status'    => 'active',
            'expires_at' => now()->addMinutes(5),
        ]);

        $resp2 = $this->get('/forgot-password/verify/' . $verification->unique_id);
        $resp2->assertStatus(200);
        $resp2->assertSee('Verifikasi Kode OTP');

        // 3. GET /reset-password/{uniqueId}
        $verification->update(['status' => 'valid']);
        $resp3 = $this->get('/reset-password/' . $verification->unique_id);
        $resp3->assertStatus(200);
        $resp3->assertSee('Atur Kata Sandi Baru');
    }

    public function test_user_without_email_auto_binds_email_and_sends_otp(): void
    {
        Mail::fake();

        // Akun belum memiliki email sama sekali (seperti hasil import SIMPEG)
        $user = new Pengguna([
            'nip' => '199501012020011099',
            'nama_lengkap' => 'Pegawai Tanpa Email Awal',
            'email' => null,
            'peran' => 'peserta',
            'status' => 'aktif',
        ]);
        $user->acakKataSandi()->save();
        $this->assertNull($user->email);

        // Buat record pegawai_simpegs
        \App\Models\PegawaiSimpeg::create([
            'nip' => $user->nip,
            'nama_lengkap' => $user->nama_lengkap,
            'email' => null,
        ]);

        $inputEmail = 'baru.didaftarkan@gmail.com';

        // 1. API Pathway
        $response = $this->postJson('/api/forgot-password', [
            'nip' => $user->nip,
            'email' => $inputEmail,
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('unique_id'));

        // Cek email tersimpan di pengguna dan pegawai_simpegs
        $this->assertEquals($inputEmail, $user->fresh()->email);
        $this->assertEquals($inputEmail, \App\Models\PegawaiSimpeg::where('nip', $user->nip)->value('email'));

        // Cek OTP terkirim
        Mail::assertQueued(ForgotPasswordOtpMail::class);
    }
}

