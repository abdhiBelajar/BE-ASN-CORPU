<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Services\RecaptchaService;

class Recaptcha implements ValidationRule
{
    /**
     * Jalankan validasi rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $ip = request()->ip();
        if (!RecaptchaService::verify($value, $ip)) {
            $fail('Verifikasi keamanan CAPTCHA tidak valid atau telah kedaluwarsa. Silakan centang kembali "Saya bukan robot".');
        }
    }
}
