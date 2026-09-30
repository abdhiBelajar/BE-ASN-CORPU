<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi OTP - BKPSDM</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f6f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: white; padding: 32px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); width: 100%; max-width: 420px; }
        h2 { color: #1D315F; margin-top: 0; font-size: 20px; }
        p { color: #6b7280; font-size: 13px; line-height: 1.5; }
        .alert { padding: 10px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .form-group { margin-bottom: 16px; }
        label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 6px; text-transform: uppercase; }
        input[type="text"] { width: 100%; box-sizing: border-box; padding: 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 22px; text-align: center; letter-spacing: 6px; font-weight: bold; font-family: monospace; }
        input:focus { outline: none; border-color: #3FCDC1; box-shadow: 0 0 0 2px rgba(63,205,193,0.2); }
        button { width: 100%; background: #1D315F; color: white; border: none; padding: 12px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 14px; }
        button:hover { background: #152747; }
        .btn-secondary { background: white; color: #4b5563; border: 1px solid #d1d5db; margin-top: 8px; }
        .btn-secondary:hover { background: #f9fafb; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Verifikasi Kode OTP</h2>
        <p>Masukkan 6 digit kode OTP yang telah dikirimkan ke email terdaftar Anda.</p>

        @if (session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-danger">
                {{ session('error') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form action="{{ route('forgot-password.verify-otp', $verification->unique_id) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="form-group">
                <label for="otp">Kode OTP (6 Digit)</label>
                <input
                    type="text"
                    id="otp"
                    name="otp"
                    maxlength="6"
                    minlength="6"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    required
                    autofocus
                    placeholder="123456"
                >
            </div>

            <button type="submit">Verifikasi Kode OTP</button>
        </form>

        <form action="{{ route('forgot-password.resend', $verification->unique_id) }}" method="POST" style="margin-top: 12px;">
            @csrf
            <button type="submit" class="btn-secondary">Kirim Ulang Kode OTP</button>
        </form>
    </div>
</body>
</html>
