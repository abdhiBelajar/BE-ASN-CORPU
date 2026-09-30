<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RecaptchaService
{
    /**
     * Verifikasi token Google reCAPTCHA ke endpoint Google siteverify
     */
    public static function verify(?string $token, ?string $ip = null): bool
    {
        $secret = config('services.recaptcha.secret_key') ?: env('RECAPTCHA_SECRET_KEY');

        // Jika secret kosong (misal di testing tanpa recaptcha), anggap valid
        if (empty($secret)) {
            return true;
        }

        if (empty($token)) {
            return false;
        }

        // Kunci test resmi Google selalu valid jika token terisi
        if ($secret === '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe') {
            return true;
        }

        try {
            $response = Http::asForm()->timeout(5)->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]);

            if ($response->successful()) {
                $result = $response->json();
                return (bool) ($result['success'] ?? false);
            }

            Log::warning('reCAPTCHA HTTP verification failed with status: ' . $response->status());
            return false;
        } catch (\Throwable $e) {
            Log::error('reCAPTCHA connection exception: ' . $e->getMessage());
            return false;
        }
    }
}
