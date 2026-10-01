<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Sertifikat Pelatihan - {{ $nomorSertifikat }}</title>
    <style>
        @page {
            size: 297mm 210mm landscape;
            margin: 0;
        }
        * {
            box-sizing: border-box;
        }
        html, body {
            margin: 0;
            padding: 0;
            width: 297mm;
            height: 210mm;
            font-family: 'Helvetica', 'Arial', sans-serif;
            color: #111827;
            position: relative;
            background-color: #FAF6EF;
        }
        .bg-container {
            position: absolute;
            top: 0;
            left: 0;
            width: 297mm;
            height: 210mm;
            z-index: 1;
        }
        .bg-image {
            width: 100%;
            height: 100%;
        }
        .content-layer {
            position: absolute;
            top: 0;
            left: 0;
            width: 297mm;
            height: 210mm;
            z-index: 10;
        }
        .main-text-area {
            position: absolute;
            top: 75px;
            left: 170px;
            right: 170px;
            text-align: center;
        }
        .cert-title {
            font-family: 'Times-Bold', 'Times New Roman', 'Georgia', serif;
            font-size: 38px;
            font-weight: bold;
            letter-spacing: 2px;
            color: #0E2540;
            margin: 0;
            padding: 0;
            text-transform: uppercase;
        }
        .cert-number {
            font-size: 13.5px;
            font-weight: bold;
            letter-spacing: 1px;
            color: #111827;
            margin-top: 6px;
        }
        .cert-subtitle {
            font-size: 15px;
            color: #374151;
            margin-top: 10px;
        }
        .participant-name {
            font-size: 24px;
            font-weight: bold;
            color: #000000;
            margin-top: 10px;
            letter-spacing: 0.5px;
        }
        .participant-nip {
            font-size: 13.5px;
            font-weight: bold;
            color: #111827;
            margin-top: 4px;
        }
        .participant-unit {
            font-size: 13px;
            color: #374151;
            margin-top: 3px;
        }
        .divider-line {
            width: 500px;
            height: 2px;
            background-color: #111827;
            margin: 10px auto 8px auto;
        }
        .role-text {
            font-size: 13.5px;
            color: #4B5563;
            margin: 0;
        }
        .course-title {
            font-size: 17px;
            font-weight: bold;
            color: #000000;
            margin-top: 7px;
            line-height: 1.35;
            max-width: 650px;
            margin-left: auto;
            margin-right: auto;
        }
        .course-desc {
            font-size: 13px;
            color: #1F2937;
            margin-top: 7px;
            line-height: 1.4;
            max-width: 650px;
            margin-left: auto;
            margin-right: auto;
        }
        .issue-date {
            font-size: 14px;
            font-weight: bold;
            color: #8A151B;
            margin-top: 12px;
        }
        .qr-section {
            position: absolute;
            bottom: 115px;
            left: 186px;
            width: 90px;
            text-align: center;
        }
        .qr-image {
            width: 90px;
            height: 90px;
            display: block;
            margin: 0 auto;
        }
    </style>
</head>
<body>
    @if (!empty($backgroundImage))
        <div class="bg-container">
            <img src="{{ $backgroundImage }}" class="bg-image" alt="Background Template">
        </div>
    @endif

    <div class="content-layer">
        <div class="main-text-area">
            <h1 class="cert-title">SERTIFIKAT</h1>
            <div class="cert-number">NO : {{ $nomorSertifikat }}</div>
            <div class="cert-subtitle">Diberikan kepada</div>
            
            <div class="participant-name">{{ $namaPeserta }}</div>
            <div class="participant-nip">NIP {{ $nip }}</div>
            <div class="participant-unit">Unit Kerja: {{ $unitKerja }}</div>

            <div class="divider-line"></div>

            <div class="role-text">Sebagai Peserta</div>
            <div class="course-title">"{!! strtoupper(e($judulPembelajaran)) !!}"</div>

            <div class="course-desc">
                yang diselenggarakan secara daring, selama {{ $durasiJp }} Jam Pelajaran (JP) oleh BKPSDM Kabupaten Buleleng
            </div>

            <div class="issue-date">{{ $tanggalTerbit }}</div>
        </div>

        @if (!empty($qrCode))
            <div class="qr-section">
                <img src="{{ $qrCode }}" class="qr-image" alt="QR Validasi">
            </div>
        @endif
    </div>
</body>
</html>
