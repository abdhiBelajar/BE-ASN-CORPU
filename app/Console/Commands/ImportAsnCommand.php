<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PegawaiSimpeg;
use App\Services\PenggunaProvisioningService;
use Illuminate\Support\Facades\DB;

class ImportAsnCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'simpeg:import 
                            {file? : Path ke file CSV data ASN} 
                            {--tanpa-akun : Jangan otomatis membuat atau memperbarui akun pengguna}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import data ASN dari file CSV ke basis data SIMPEG lokal (pegawai_simpegs)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = $this->argument('file');

        if (!$filePath) {
            $defaultPaths = [
                database_path('data/db_peg_bkpsdm_test_lms.csv'),
                base_path('../db_peg_bkpsdm_test_lms.csv'),
            ];

            foreach ($defaultPaths as $path) {
                if (file_exists($path)) {
                    $filePath = $path;
                    break;
                }
            }
        }

        if (!$filePath || !file_exists($filePath)) {
            $this->error("File CSV tidak ditemukan di: " . ($filePath ?: '(belum ditentukan)'));
            return 1;
        }

        $tanpaAkun = (bool) $this->option('tanpa-akun');
        $this->info("Memulai import data ASN dari: {$filePath}");

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            $this->error("Gagal membuka file: {$filePath}");
            return 1;
        }

        $header = fgetcsv($handle, 1000, ',');
        if (!$header) {
            $this->error("File CSV kosong.");
            fclose($handle);
            return 1;
        }

        $cleanHeader = array_map(function ($col) {
            return strtoupper(trim($col));
        }, $header);

        $totalRows = 0;
        $simpegCount = 0;

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                if (count($row) < count($cleanHeader)) {
                    continue;
                }

                $totalRows++;
                $data = array_combine($cleanHeader, $row);

                $nip = trim($data['NIP'] ?? '');
                $nama = trim($data['NAMA'] ?? '');
                $jabatan = trim($data['JABATAN'] ?? '');
                $kode = strtoupper(trim($data['KODE'] ?? ''));
                $jenisJabatan = strtoupper(trim($data['JENIS JABATAN'] ?? ''));
                $unitKerja = trim($data['UNIT KERJA'] ?? '');

                // Kolom email opsional
                $emailRaw = strtolower(trim((string) ($data['EMAIL'] ?? '')));
                $emailValid = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? $emailRaw : null;

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

                // 1. Simpan ke database SIMPEG lokal
                $pegawaiData = [
                    'nama_lengkap' => $nama,
                    'jabatan' => $jabatan ?: null,
                    'rumpun_jabatan' => $rumpun,
                    'unit_kerja' => $unitKerja ?: null,
                ];
                if ($emailValid) {
                    $pegawaiData['email'] = $emailValid;
                }

                PegawaiSimpeg::updateOrCreate(
                    ['nip' => $nip],
                    $pegawaiData
                );
                $simpegCount++;
            }

            DB::commit();
            fclose($handle);

            $this->info("Import berhasil!");
            $this->line("- Total data ASN diproses: {$simpegCount} pegawai terdaftar di data SIMPEG lokal.");

            if (!$tanpaAkun) {
                $this->info("Menjalankan provisioning akun pengguna...");
                $stats = app(PenggunaProvisioningService::class)->provisionSemua();
                $this->line("- Total akun dibuat: {$stats['dibuat']}, diperbarui: {$stats['diperbarui']}, dilewati: {$stats['dilewati']}.");
                $this->line("- Akun dibuat dengan sandi acak. Pegawai membuat sandi lewat Lupa Kata Sandi (perlu email terdaftar).");
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($handle);
            $this->error("Terjadi kesalahan saat import: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
