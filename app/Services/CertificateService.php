<?php

namespace App\Services;

use App\Models\Sertifikat;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class CertificateService
{
    /**
     * Romawi bulan untuk penomoran surat dinas
     */
    protected static array $romawiBulan = [
        1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV',
        5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII',
        9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII'
    ];

    /**
     * Generate nomor sertifikat resmi dinas:
     * Format: 800/{NOMOR URUT}/{ANGKA ROMAWI BULAN}/BKPSDM/{TAHUN}
     */
    public function generateNomorSertifikat(?Carbon $date = null): string
    {
        $date = $date ?: Carbon::now();
        $year = $date->year;
        $month = $date->month;
        $romawi = self::$romawiBulan[$month] ?? 'I';

        // Hitung nomor urut berikutnya pada tahun berjalan
        $countThisYear = Sertifikat::whereYear('tanggal_terbit', $year)->count();
        $nextNumber = str_pad($countThisYear + 1, 4, '0', STR_PAD_LEFT);

        return "800/{$nextNumber}/{$romawi}/BKPSDM/{$year}";
    }

    /**
     * Generate verification code acak dan unik untuk URL QR Code
     */
    public function generateVerificationCode(): string
    {
        do {
            $code = 'BKPSDM-' . strtoupper(Str::random(6)) . '-' . strtoupper(dechex(time()));
        } while (Sertifikat::where('verification_code', $code)->exists());

        return $code;
    }

    /**
     * Generate QR Code SVG Data URI offline (tanpa ketergantungan API pihak ketiga)
     */
    public function getQrCodeDataUri(string $verificationCode): string
    {
        $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');
        $verifyUrl = "{$frontendUrl}/validasi-sertifikat/{$verificationCode}";

        $svg = QrCode::format('svg')
            ->size(135)
            ->margin(0)
            ->errorCorrection('H')
            ->generate($verifyUrl);

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Ambil background sertifikat beresolusi tinggi dalam format base64
     */
    public function getBackgroundBase64(): string
    {
        $path = public_path('template_sertifikat_pristine_bg.png');
        if (!file_exists($path)) {
            $path = public_path('template_sertifikat_clean.png');
        }

        if (file_exists($path)) {
            return 'data:image/png;base64,' . base64_encode(file_get_contents($path));
        }

        return '';
    }

    /**
     * Ambil tanda tangan pejabat dalam format base64
     */
    public function getTtdBase64(): string
    {
        $path = public_path('ttd_pejabat.png');
        if (file_exists($path)) {
            return 'data:image/png;base64,' . base64_encode(file_get_contents($path));
        }

        return '';
    }

    /**
     * Render DomPDF instance untuk sertifikat
     */
    public function renderPdf(Sertifikat $sertifikat)
    {
        $sertifikat->loadMissing(['pendaftaran.pembelajaran.pembelajaranJp', 'pendaftaran.pengguna']);

        $pembelajaran = $sertifikat->pendaftaran ? $sertifikat->pendaftaran->pembelajaran : null;
        $pengguna = $sertifikat->pendaftaran ? $sertifikat->pendaftaran->pengguna : null;
        $jpDet = $pembelajaran && $pembelajaran->pembelajaranJp ? $pembelajaran->pembelajaranJp->first() : null;

        // Pastikan verification_code ada
        if (empty($sertifikat->verification_code)) {
            $sertifikat->verification_code = $this->generateVerificationCode();
            $sertifikat->save();
        }

        $durasiJp = $jpDet && $jpDet->jp_final ? (float)$jpDet->jp_final : 0;
        $durasiJpFormatted = (int)$durasiJp == $durasiJp ? (int)$durasiJp : $durasiJp;

        $tglTerbit = Carbon::parse($sertifikat->tanggal_terbit)->locale('id');
        $tanggalTerbitFormatted = $tglTerbit->translatedFormat('d F Y');

        $data = [
            'backgroundImage' => $this->getBackgroundBase64(),
            'ttdImage' => $this->getTtdBase64(),
            'qrCode' => $this->getQrCodeDataUri($sertifikat->verification_code),
            'verificationCode' => $sertifikat->verification_code,
            'nomorSertifikat' => $sertifikat->nomor_sertifikat,
            'namaPeserta' => $sertifikat->nama_lengkap_snapshot ?: ($pengguna->nama_lengkap ?? 'Peserta'),
            'nip' => $sertifikat->nip_snapshot ?: ($pengguna->nip ?? '-'),
            'unitKerja' => $sertifikat->unit_kerja_snapshot ?: ($pengguna->unit_kerja ?? 'Pemerintah Kabupaten Buleleng'),
            'judulPembelajaran' => $pembelajaran ? $pembelajaran->judul_pembelajaran : 'Pelatihan BKPSDM',
            'durasiJp' => $durasiJpFormatted,
            'tanggalTerbit' => $tanggalTerbitFormatted,
            'pejabatNama' => 'I MADE DWI ADNYANA, S.STP., M.A.P',
            'pejabatNip' => '197612281996011001',
            'pejabatJabatan' => 'Kepala BKPSDM Kabupaten Buleleng',
        ];

        return Pdf::loadView('pdf.sertifikat', $data)
            ->setPaper('a4', 'landscape')
            ->setOption([
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => true,
                'dpi' => 150,
                'defaultFont' => 'Helvetica'
            ]);
    }
}
