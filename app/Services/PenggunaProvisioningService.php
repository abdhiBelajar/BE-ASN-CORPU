<?php

namespace App\Services;

use App\Models\PegawaiSimpeg;
use App\Models\Pengguna;
use App\Models\PenggunaPeran;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PenggunaProvisioningService
{
    /**
     * Provisioning satu pegawai ke tabel pengguna.
     * Return: 'dibuat' | 'diperbarui' | 'dilewati'
     */
    public function provision(PegawaiSimpeg $p): string
    {
        // 1. Cari Pengguna::withTrashed()->where('nip', $p->nip)->first()
        $pengguna = Pengguna::withTrashed()->where('nip', $p->nip)->first();

        // 2. Jika ada dan trashed() -> return 'dilewati' (D7)
        if ($pengguna && $pengguna->trashed()) {
            return 'dilewati';
        }

        // 3. Jika strlen($p->nip) > 20 (kolom pengguna.nip hanya 20 karakter) -> 'dilewati' dan Log::warning
        if (strlen($p->nip) > 20) {
            Log::warning("NIP pegawai melebihi batas 20 karakter: {$p->nip}");
            return 'dilewati';
        }

        // 4. Normalisasi email pegawai: strtolower(trim(...)), valid jika lolos filter_var(FILTER_VALIDATE_EMAIL)
        // dan belum dipakai pengguna lain (kolom email unique). Jika bentrok, pakai null dan catat di Log::warning
        $emailValid = null;
        if (!empty($p->email)) {
            $normalized = strtolower(trim($p->email));
            if (filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
                $bentrok = Pengguna::where('email', $normalized)
                    ->when($pengguna, fn ($q) => $q->where('pengguna_id', '!=', $pengguna->pengguna_id))
                    ->exists();

                if ($bentrok) {
                    Log::warning("Email {$normalized} untuk NIP {$p->nip} bentrok dengan pengguna lain.");
                } else {
                    $emailValid = $normalized;
                }
            }
        }

        // 5. Jika akun sudah ada -> update hanya: nama_lengkap, jabatan, rumpun_jabatan, unit_kerja.
        // Update email hanya jika email akun kosong atau berupa placeholder lama (/^\d+@asn\.bulelengkab\.go\.id$/i) dan email pegawai valid.
        // Jangan pernah menyentuh kata_sandi_hash, kata_sandi_diatur_pada, peran, status, atau tabel pengguna_peran.
        if ($pengguna) {
            $pengguna->nama_lengkap = $p->nama_lengkap;
            $pengguna->jabatan = $p->jabatan;
            $pengguna->rumpun_jabatan = $p->rumpun_jabatan;
            $pengguna->unit_kerja = $p->unit_kerja;

            $isPlaceholder = !empty($pengguna->email) && preg_match('/^\d+@asn\.bulelengkab\.go\.id$/i', $pengguna->email);
            if ((empty($pengguna->email) || $isPlaceholder) && $emailValid) {
                $pengguna->email = $emailValid;
            }

            $pengguna->save();
            return 'diperbarui';
        }

        // 6. Jika belum ada -> dalam DB::transaction
        return DB::transaction(function () use ($p, $emailValid) {
            $u = new Pengguna([
                'nip' => $p->nip,
                'nama_lengkap' => $p->nama_lengkap,
                'email' => $emailValid,
                'peran' => 'peserta',
                'jabatan' => $p->jabatan,
                'rumpun_jabatan' => $p->rumpun_jabatan,
                'unit_kerja' => $p->unit_kerja,
                'status' => 'aktif',
            ]);
            $u->acakKataSandi()->save();

            PenggunaPeran::create([
                'pengguna_id' => $u->pengguna_id,
                'peran' => 'peserta',
            ]);

            return 'dibuat';
        });
    }

    /**
     * Provisioning semua data pegawai di tabel pegawai_simpegs
     * Return: ['dibuat' => n, 'diperbarui' => n, 'dilewati' => n, 'tanpa_email' => n]
     */
    public function provisionSemua(bool $dryRun = false): array
    {
        $stats = [
            'dibuat' => 0,
            'diperbarui' => 0,
            'dilewati' => 0,
            'tanpa_email' => 0,
        ];

        PegawaiSimpeg::query()->chunkById(500, function ($pegawaiList) use (&$stats, $dryRun) {
            foreach ($pegawaiList as $p) {
                if ($dryRun) {
                    if (strlen($p->nip) > 20) {
                        $stats['dilewati']++;
                        continue;
                    }

                    $pengguna = Pengguna::withTrashed()->where('nip', $p->nip)->first();
                    if ($pengguna && $pengguna->trashed()) {
                        $stats['dilewati']++;
                        continue;
                    }

                    $emailValid = null;
                    if (!empty($p->email)) {
                        $normalized = strtolower(trim($p->email));
                        if (filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
                            $bentrok = Pengguna::where('email', $normalized)
                                ->when($pengguna, fn ($q) => $q->where('pengguna_id', '!=', $pengguna->pengguna_id))
                                ->exists();
                            if (!$bentrok) {
                                $emailValid = $normalized;
                            }
                        }
                    }

                    if ($pengguna) {
                        $stats['diperbarui']++;
                        $isPlaceholder = !empty($pengguna->email) && preg_match('/^\d+@asn\.bulelengkab\.go\.id$/i', $pengguna->email);
                        $finalEmail = ((empty($pengguna->email) || $isPlaceholder) && $emailValid) ? $emailValid : $pengguna->email;
                        if (empty($finalEmail) || preg_match('/^\d+@asn\.bulelengkab\.go\.id$/i', (string)$finalEmail)) {
                            $stats['tanpa_email']++;
                        }
                    } else {
                        $stats['dibuat']++;
                        if (!$emailValid) {
                            $stats['tanpa_email']++;
                        }
                    }
                } else {
                    $status = $this->provision($p);
                    $stats[$status]++;

                    if ($status !== 'dilewati') {
                        $pengguna = Pengguna::where('nip', $p->nip)->first();
                        if (!$pengguna || empty($pengguna->email) || preg_match('/^\d+@asn\.bulelengkab\.go\.id$/i', (string)$pengguna->email)) {
                            $stats['tanpa_email']++;
                        }
                    }
                }
            }
        });

        return $stats;
    }
}
