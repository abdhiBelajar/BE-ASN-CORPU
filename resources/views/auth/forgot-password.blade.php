<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Kata Sandi - BKPSDM</title>
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
        input[type="text"], input[type="email"] { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
        input:focus { outline: none; border-color: #3FCDC1; box-shadow: 0 0 0 2px rgba(63,205,193,0.2); }
        button { width: 100%; background: #1D315F; color: white; border: none; padding: 12px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 14px; }
        button:hover { background: #152747; }
        .back-link { display: block; text-align: center; margin-top: 16px; color: #6b7280; font-size: 13px; text-decoration: none; }
        .back-link:hover { color: #1D315F; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Lupa Kata Sandi</h2>
        <p>Masukkan NIP dan alamat email Anda untuk menerima 6 digit kode OTP verifikasi.</p>

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

        <form action="{{ route('forgot-password.send-otp') }}" method="POST">
            @csrf
            <div class="form-group">
                <label for="nip">NIP (Nomor Induk Pegawai)</label>
                <input type="text" id="nip" name="nip" value="{{ old('nip') }}" required autofocus placeholder="Masukkan 18 digit NIP Anda">
            </div>

            <div class="form-group">
                <label for="email">Alamat Email (Untuk Menerima OTP)</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required placeholder="contoh: user@bkpsdm.go.id">
            </div>

            <button type="submit">Kirim Kode OTP</button>
        </form>

        <a href="/" class="back-link">&larr; Kembali ke Halaman Utama</a>
    </div>
</body>
</html>
