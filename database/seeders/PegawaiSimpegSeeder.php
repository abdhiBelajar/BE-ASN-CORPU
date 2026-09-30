<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PegawaiSimpeg;
use Illuminate\Support\Facades\Log;

class PegawaiSimpegSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $possiblePaths = [
            database_path('data/db_peg_bkpsdm_test_lms.csv'),
            base_path('../db_peg_bkpsdm_test_lms.csv'),
            base_path('database/data/db_peg_bkpsdm_test_lms.csv'),
        ];

        $filePath = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $filePath = $path;
                break;
            }
        }

        if (!$filePath) {
            $this->command->error("File CSV data pegawai SIMPEG tidak ditemukan.");
            return;
        }

        $this->command->info("Mengimpor data ASN SIMPEG dari: {$filePath}");

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            $this->command->error("Gagal membuka file CSV.");
            return;
        }

        // Baca header CSV
        $header = fgetcsv($handle, 1000, ',');
        if (!$header) {
            $this->command->error("Header CSV kosong.");
            fclose($handle);
            return;
        }

        // Normalisasi nama kolom header
        $cleanHeader = array_map(function ($col) {
            return strtoupper(trim($col));
        }, $header);

        $inserted = 0;
        $updated = 0;

        while (($row = fgetcsv($handle, 1000, ',')) !== false) {
            if (count($row) < count($cleanHeader)) {
                continue;
            }

            $data = array_combine($cleanHeader, $row);

            $nip = trim($data['NIP'] ?? '');
            $nama = trim($data['NAMA'] ?? '');
            $jabatan = trim($data['JABATAN'] ?? '');
            $kode = strtoupper(trim($data['KODE'] ?? ''));
            $jenisJabatan = strtoupper(trim($data['JENIS JABATAN'] ?? ''));
            $unitKerja = trim($data['UNIT KERJA'] ?? '');

            if (empty($nip) || !is_numeric($nip)) {
                continue;
            }

            // Normalisasi rumpun_jabatan: JP, JA, JF, JPT
            $rumpun = 'JP';
            if ($kode === 'JPT' || str_contains($jenisJabatan, 'PIMPINAN TINGGI')) {
                $rumpun = 'JPT';
            } elseif ($kode === 'JA' || str_contains($jenisJabatan, 'ADMINISTRATOR') || str_contains($jenisJabatan, 'PENGAWAS')) {
                $rumpun = 'JA';
            } elseif ($kode === 'JF' || str_contains($jenisJabatan, 'FUNGSIONAL')) {
                $rumpun = 'JF';
            } elseif ($kode === 'JP' || str_contains($jenisJabatan, 'PELAKSANA')) {
                $rumpun = 'JP';
            }

            // Kolom email opsional
            $emailRaw = strtolower(trim((string) ($data['EMAIL'] ?? '')));
            $emailValid = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? $emailRaw : null;

            $pegawaiData = [
                'nama_lengkap' => $nama,
                'jabatan' => $jabatan ?: null,
                'rumpun_jabatan' => $rumpun,
                'unit_kerja' => $unitKerja ?: null,
            ];
            if ($emailValid) {
                $pegawaiData['email'] = $emailValid;
            }

            $pegawai = PegawaiSimpeg::updateOrCreate(
                ['nip' => $nip],
                $pegawaiData
            );

            if ($pegawai->wasRecentlyCreated) {
                $inserted++;
            } else {
                $updated++;
            }
        }

        fclose($handle);

        $this->command->info("Selesai! {$inserted} pegawai baru ditambahkan, {$updated} pegawai diperbarui ke tabel pegawai_simpegs.");

        // Provisioning ke akun pengguna
        $this->command->info("Melakukan sinkronisasi akun pengguna...");
        $stats = app(\App\Services\PenggunaProvisioningService::class)->provisionSemua();
        $this->command->info("Hasil sinkronisasi: {$stats['dibuat']} dibuat, {$stats['diperbarui']} diperbarui, {$stats['tanpa_email']} tanpa email.");
    }
}
