<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kode OTP Reset Password</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 24px; color: #333333;">
    <div style="max-width: 580px; margin: 0 auto; background-color: #ffffff; padding: 32px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); border: 1px solid #e5e7eb;">
        
        <div style="border-bottom: 2px solid #3FCDC1; padding-bottom: 16px; margin-bottom: 24px;">
            <h2 style="color: #1D315F; margin: 0; font-size: 20px;">LMS ASN Buleleng</h2>
            <p style="color: #6b7280; margin: 4px 0 0 0; font-size: 13px;">BKPSDM Kabupaten Buleleng</p>
        </div>

        <p style="font-size: 14px; line-height: 1.6; color: #374151; margin-top: 0;">
            Halo Bapak/Ibu Pegawai ASN,
        </p>

        <p style="font-size: 14px; line-height: 1.6; color: #374151;">
            Berikut adalah kode verifikasi untuk mengatur kata sandi akun LMS Anda:
        </p>

        <div style="text-align: center; margin: 24px 0; background-color: #f0fdfa; border: 1px solid #99f6e4; border-radius: 8px; padding: 18px;">
            <span style="display: inline-block; font-size: 32px; font-weight: bold; color: #0f766e; letter-spacing: 6px; font-family: monospace;">
                {{ $otp }}
            </span>
            <p style="color: #0d9488; font-size: 12px; margin: 6px 0 0 0;">
                Kode ini berlaku selama 5 menit
            </p>
        </div>

        <p style="font-size: 13px; line-height: 1.5; color: #6b7280;">
            Demi keamanan akun Anda, jangan bagikan kode ini kepada pihak lain. Jika Anda tidak merasa meminta kode ini, Anda dapat mengabaikan email ini.
        </p>

        <hr style="border: none; border-top: 1px solid #f3f4f6; margin: 24px 0 16px 0;">

        <p style="text-align: center; color: #9ca3af; font-size: 11px; margin: 0;">
            &copy; {{ date('Y') }} BKPSDM Kabupaten Buleleng.
        </p>
    </div>
</body>
</html>
