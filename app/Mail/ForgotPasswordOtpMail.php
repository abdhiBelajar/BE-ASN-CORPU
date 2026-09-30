<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

class ForgotPasswordOtpMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $otp;

    public function __construct(string $otp)
    {
        $this->otp = $otp;
    }

    public function build()
    {
        return $this
            ->subject('Kode Verifikasi Akses LMS Buleleng')
            ->view('emails.forgot-password-otp')
            ->text('emails.forgot-password-otp-text')
            ->replyTo(config('mail.from.address'), config('mail.from.name'))
            ->withSymfonyMessage(function (Email $email) {
                $email->getHeaders()
                    ->addTextHeader('X-Auto-Response-Suppress', 'All')
                    ->addTextHeader('Auto-Submitted', 'auto-generated');
            });
    }
}
