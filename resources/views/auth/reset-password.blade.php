<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atur Kata Sandi Baru - BKPSDM</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f6f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: white; padding: 32px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); width: 100%; max-width: 420px; }
        h2 { color: #1D315F; margin-top: 0; font-size: 20px; }
        p { color: #6b7280; font-size: 13px; line-height: 1.5; }
        .alert { padding: 10px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 16px; }
        .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .form-group { margin-bottom: 16px; }
        label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 6px; text-transform: uppercase; }
        input[type="password"] { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
        input:focus { outline: none; border-color: #3FCDC1; box-shadow: 0 0 0 2px rgba(63,205,193,0.2); }
        .hint { font-size: 11px; color: #6b7280; margin-top: 4px; line-height: 1.4; }
        button { width: 100%; background: #1D315F; color: white; border: none; padding: 12px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 14px; margin-top: 8px; }
        button:hover { background: #152747; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Atur Kata Sandi Baru</h2>
        <p>Silakan masukkan kata sandi baru Anda untuk akun LMS BKPSDM.</p>

        @if ($errors->any())
            <div class="alert alert-danger">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form action="{{ route('forgot-password.update', $verification->unique_id) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="form-group">
                <label for="password">Kata Sandi Baru</label>
                <input type="password" id="password" name="password" required autofocus placeholder="Minimal 8 karakter">
                <div class="hint">Minimal 8 karakter, kombinasi huruf besar, kecil, angka, simbol, dan tidak mengandung NIP.</div>
            </div>

            <div class="form-group">
                <label for="password_confirmation">Konfirmasi Kata Sandi</label>
                <input type="password" id="password_confirmation" name="password_confirmation" required placeholder="Ulangi kata sandi baru">
            </div>

            <button type="submit">Simpan Kata Sandi</button>
        </form>
    </div>
</body>
</html>
