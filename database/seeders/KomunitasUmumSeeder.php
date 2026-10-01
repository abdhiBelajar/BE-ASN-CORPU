<?php

namespace Database\Seeders;

use App\Models\Komunitas;
use App\Models\Pengguna;
use Illuminate\Database\Seeder;
use RuntimeException;

class KomunitasUmumSeeder extends Seeder
{
    public function run(): void
    {
        // Pembuat wajib pengguna admin_bkpsdm (FK dibuat_oleh_pengguna_id).
        $pembuat = Pengguna::where('peran', 'admin_bkpsdm')->first()
            ?? Pengguna::whereHas('daftarPeran', fn ($q) => $q->where('peran', 'admin_bkpsdm'))->first();

        if (!$pembuat) {
            $this->command?->warn('Admin BKPSDM belum ada. Jalankan AdminBkpsdmSeeder dulu.');
            return;
        }

        // Pengecekan eksplisit unik: jika ada komunitas lain bernama 'Komunitas Umum' tapi bukan UMUM
        $komunitasKonflik = Komunitas::withTrashed()
            ->where('nama_komunitas', 'Komunitas Umum')
            ->where('rumpun_jabatan', '!=', Komunitas::RUMPUN_UMUM)
            ->first();

        if ($komunitasKonflik) {
            throw new RuntimeException("Gagal melakukan seed: Sudah ada komunitas bernama 'Komunitas Umum' dengan rumpun jabatan {$komunitasKonflik->rumpun_jabatan}.");
        }

        Komunitas::withTrashed()->updateOrCreate(
            ['rumpun_jabatan' => Komunitas::RUMPUN_UMUM],
            [
                'nama_komunitas'          => 'Komunitas Umum',
                'deskripsi'               => 'Komunitas terbuka untuk seluruh pegawai dari semua rumpun jabatan (JPT, JA, JF, JP). Materi dapat dibuat oleh admin komunitas dari semua rumpun.',
                'dibuat_oleh_pengguna_id' => $pembuat->pengguna_id,
                'status'                  => 'aktif',
                'deleted_at'              => null,
            ]
        );

        Komunitas::resetUmumIdCache();
    }
}
