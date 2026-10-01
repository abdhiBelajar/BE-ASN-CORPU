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
            top: 27.5mm;
            left: 45mm;
            width: 207mm;
            text-align: center;
        }
        .cert-title {
            font-family: 'Times-Bold', 'Times New Roman', Georgia, serif;
            font-size: 44pt;
            font-weight: bold;
            letter-spacing: 2.5px;
            color: #0E2540;
            margin: 0;
            padding: 0;
            text-transform: uppercase;
            line-height: 1;
        }
        .cert-number {
            font-size: 12.5pt;
            font-weight: bold;
            letter-spacing: 1.5px;
            color: #0E2540;
            margin-top: 3.5mm;
        }
        .cert-subtitle {
            font-size: 13pt;
            color: #1F2937;
            margin-top: 3mm;
        }
        .participant-name {
            font-size: 25pt;
            font-weight: bold;
            color: #000000;
            margin-top: 3.5mm;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }
        .participant-nip {
            font-size: 13pt;
            font-weight: bold;
            color: #000000;
            margin-top: 1.5mm;
        }
        .participant-unit {
            font-size: 11.5pt;
            font-weight: bold;
            color: #000000;
            margin-top: 1.5mm;
            line-height: 1.3;
        }
        .divider-line {
            width: 170mm;
            border-top: 2px solid #000000;
            margin: 3.5mm auto 3.5mm auto;
        }
        .role-text {
            font-size: 13pt;
            color: #1F2937;
            margin: 0;
        }
        .course-title {
            font-size: 16pt;
            font-weight: bold;
            color: #000000;
            margin-top: 2.5mm;
            line-height: 1.35;
            text-transform: uppercase;
        }
        .course-desc {
            font-size: 11.5pt;
            color: #1F2937;
            margin-top: 2.5mm;
            line-height: 1.4;
        }
        .issue-date {
            font-size: 14.5pt;
            font-weight: bold;
            color: #7A0C16;
            margin-top: 4.5mm;
        }
        .qr-section {
            position: absolute;
            left: 46.5mm;
            top: 151mm;
            width: 26mm;
            height: 26mm;
            z-index: 10;
        }
        .qr-image {
            width: 26mm;
            height: 26mm;
            display: block;
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
