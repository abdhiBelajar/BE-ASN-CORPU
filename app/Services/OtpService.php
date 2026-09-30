<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class OtpService
{
    private const TTL_DETIK = 600;      // 10 menit
    private const MAKS_SALAH = 5;
    private const COOLDOWN_DETIK = 60;

    /** Return OTP plaintext untuk dikirim lewat email, atau null jika masih dalam cooldown. */
    public function terbitkan(string $tujuan, string $kunci): ?string
    {
        if (!Cache::add("otp:cd:$tujuan:$kunci", 1, self::COOLDOWN_DETIK)) {
            return null;
        }
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put("otp:$tujuan:$kunci", [
            'hmac'  => $this->hmac($otp),
            'salah' => 0,
            'exp'   => now()->timestamp + self::TTL_DETIK,
        ], self::TTL_DETIK);
        return $otp;
    }

    public function verifikasi(string $tujuan, string $kunci, string $input): bool
    {
        $k = "otp:$tujuan:$kunci";
        $d = Cache::get($k);
        if (!$d || now()->timestamp > $d['exp']) {
            Cache::forget($k);
            return false;
        }
        if (!hash_equals($d['hmac'], $this->hmac($input))) {
            $d['salah']++;
            $d['salah'] >= self::MAKS_SALAH
                ? Cache::forget($k)
                : Cache::put($k, $d, max(1, $d['exp'] - now()->timestamp));
            return false;
        }
        Cache::forget($k); // sekali pakai
        return true;
    }

    private function hmac(string $otp): string
    {
        return hash_hmac('sha256', $otp, config('app.key'));
    }
}
