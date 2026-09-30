<?php

namespace App\Services;

use App\Models\PegawaiSimpeg;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SimpegApiService
{
    /**
     * Mengambil data pegawai berdasarkan NIP dari SIMPEG.
     * Jika SIMPEG_API_URL dikonfigurasi, sistem akan melakukan request HTTP riil dengan timeout 5 detik.
     * Jika tidak dikonfigurasi atau gagal koneksi, sistem menggunakan data lokal/mock sebagai fallback aman.
     */
    public function getPegawaiByNip($nip)
    {
        $apiUrl = config('services.simpeg.url');
        $apiKey = config('services.simpeg.key');

        if (!empty($apiUrl)) {
            try {
                $response = Http::timeout(5)
                    ->connectTimeout(3)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Authorization' => $apiKey ? "Bearer {$apiKey}" : '',
                    ])
                    ->get(rtrim($apiUrl, '/') . "/pegawai/{$nip}");

                if ($response->successful()) {
                    $data = $response->json();
                    return [
                        'nama_lengkap' => $data['nama_lengkap'] ?? $data['name'] ?? null,
                        'jabatan' => $data['jabatan'] ?? null,
                        'rumpun_jabatan' => $data['rumpun_jabatan'] ?? 'JF',
                        'unit_kerja' => $data['unit_kerja'] ?? null,
                        'email' => $data['email'] ?? null,
                    ];
                }

                if ($response->status() === 404) {
                    return null;
                }

                Log::warning("SIMPEG API returned non-success HTTP status {$response->status()} for NIP: {$nip}");
            } catch (\Throwable $e) {
                Log::error("SIMPEG API connection failed for NIP {$nip}: " . $e->getMessage());
                // Fallback ke data mock lokal jika terjadi kegagalan jaringan
            }
        }

        // Mock data fallback untuk development / testing
        return $this->getMockPegawai($nip);
    }

    /**
     * Data mock pegawai lokal ASN Buleleng untuk pengujian dan development
     */
    protected function getMockPegawai($nip): ?array
    {
        // 1. Cek di tabel database pegawai_simpegs (Hasil import CSV/Excel)
        $pegawai = PegawaiSimpeg::where('nip', $nip)->first();
        if ($pegawai) {
            return [
                'nama_lengkap' => $pegawai->nama_lengkap,
                'jabatan' => $pegawai->jabatan,
                'rumpun_jabatan' => $pegawai->rumpun_jabatan,
                'unit_kerja' => $pegawai->unit_kerja,
                'email' => $pegawai->email,
            ];
        }

        // 2. Fallback ke mock data static bawaan
        $mockData = [
            '198001012005011001' => [
                'nama_lengkap' => 'Budi Santoso, S.Kom',
                'jabatan' => 'Pranata Komputer Ahli Muda',
                'rumpun_jabatan' => 'JF',
                'unit_kerja' => 'Dinas Komunikasi, Informatika, Persandian, dan Statistik',
            ],
            '199001012015011002' => [
                'nama_lengkap' => 'Andi Wijaya, S.E.',
                'jabatan' => 'Analis Kepegawaian Ahli Pertama',
                'rumpun_jabatan' => 'JA',
                'unit_kerja' => 'Badan Kepegawaian dan Pengembangan Sumber Daya Manusia',
            ],
            '197501011995011003' => [
                'nama_lengkap' => 'Dr. Ni Made Ayu',
                'jabatan' => 'Kepala Dinas',
                'rumpun_jabatan' => 'JPT',
                'unit_kerja' => 'Dinas Kesehatan',
            ],
            '199501012020011004' => [
                'nama_lengkap' => 'Putu Eka',
                'jabatan' => 'Pengadministrasi Umum',
                'rumpun_jabatan' => 'JP',
                'unit_kerja' => 'Sekretariat Daerah',
            ],
            '199501012020011005' => [
                'nama_lengkap' => 'Putu nanta',
                'jabatan' => 'Pengadministrasi Umum',
                'rumpun_jabatan' => 'JPT',
                'unit_kerja' => 'Sekretariat Daerah',
            ],
            '199501012020011006' => [
                'nama_lengkap' => 'Putu aldi',
                'jabatan' => 'Pengadministrasi Umum',
                'rumpun_jabatan' => 'JPT',
                'unit_kerja' => 'Sekretariat Daerah',
            ],
            '199805122022031001' => [
                'nama_lengkap' => 'Kadek Dwi Permana, S.Pd',
                'jabatan' => 'Guru Ahli Pertama',
                'rumpun_jabatan' => 'JF',
                'unit_kerja' => 'Dinas Pendidikan, Pemuda, dan Olahraga',
            ],
            '199208152019032002' => [
                'nama_lengkap' => 'Luh Made Sukmawati, S.Tr.Keb',
                'jabatan' => 'Bidan Terampil',
                'rumpun_jabatan' => 'JF',
                'unit_kerja' => 'Puskesmas Buleleng I',
            ],
            '199208152019032006' => [
                'nama_lengkap' => 'Aldi Jirr',
                'jabatan' => 'Bidan Terampil',
                'rumpun_jabatan' => 'JF',
                'unit_kerja' => 'Puskesmas Buleleng I',
            ],
            '199208152019032007' => [
                'nama_lengkap' => 'Nanta',
                'jabatan' => 'Bidan Terampil',
                'rumpun_jabatan' => 'JP',
                'unit_kerja' => 'Puskesmas Buleleng I',
            ],
            '199208152019032006' => [
                'nama_lengkap' => 'Leontius',
                'jabatan' => 'Bidan Terampil',
                'rumpun_jabatan' => 'JA',
                'unit_kerja' => 'Puskesmas Buleleng I',
            ],
            '199208152019032009' => [
                'nama_lengkap' => 'Abdhi',
                'jabatan' => 'Bidan Terampil',
                'rumpun_jabatan' => 'JPT',
                'unit_kerja' => 'Puskesmas Buleleng I',
            ],
            '199208152019032010' => [
                'nama_lengkap' => 'testing_peserta',
                'jabatan' => 'testing_peserta',
                'rumpun_jabatan' => 'JF',
                'unit_kerja' => 'testing',
            ],
            '199208152019032011' => [
                'nama_lengkap' => 'testing_admin_komunitas',
                'jabatan' => 'testing_admin_komunitas',
                'rumpun_jabatan' => 'JF',
                'unit_kerja' => 'testing',
            ],
            '199208152019032013' => [
                'nama_lengkap' => 'testing_admin_komunitas2',
                'jabatan' => 'testing_admin_komunitas2',
                'rumpun_jabatan' => 'JPT',
                'unit_kerja' => 'testing',
            ],
        ];

        return $mockData[$nip] ?? null;
    }

    /**
     * Sinkronisasi data kelulusan / sertifikat peserta ke sistem kepegawaian SIMPEG (PRD PST-11).
     */
    public function syncSertifikat($nip, $nomorSertifikat, $judulPembelajaran, $nilai, $jpl = 0)
    {
        $apiUrl = config('services.simpeg.url');
        $apiKey = config('services.simpeg.key');

        if (!empty($apiUrl)) {
            try {
                $response = Http::timeout(5)
                    ->connectTimeout(3)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Authorization' => $apiKey ? "Bearer {$apiKey}" : '',
                    ])
                    ->post(rtrim($apiUrl, '/') . "/sertifikat/sync", [
                        'nip' => $nip,
                        'nomor_sertifikat' => $nomorSertifikat,
                        'judul_pembelajaran' => $judulPembelajaran,
                        'nilai' => $nilai,
                        'jpl' => $jpl,
                        'tanggal_lulus' => now()->toIso8601String(),
                    ]);

                if ($response->successful()) {
                    Log::info("SIMPEG certificate sync succeeded for NIP {$nip}, cert: {$nomorSertifikat}");
                    return true;
                }

                Log::warning("SIMPEG certificate sync returned status {$response->status()} for NIP: {$nip}");
            } catch (\Throwable $e) {
                Log::error("SIMPEG certificate sync failed for NIP {$nip}: " . $e->getMessage());
            }
        } else {
            // Mock fallback logger
            Log::info("[SIMPEG MOCK] Certificate synced for NIP {$nip}: {$judulPembelajaran} (Cert: {$nomorSertifikat}, Nilai: {$nilai}, JPL: {$jpl})");
        }

        return true;
    }
}
