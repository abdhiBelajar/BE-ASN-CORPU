<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Pengguna;
use App\Models\PenggunaPeran;
use App\Models\Komunitas;
use App\Models\AdminKomunitas;

class AdminKomunitasSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (!app()->environment('local', 'testing')) {
            return;
        }

        $adminKomunitas = Pengguna::where('nip', '199001012026011002')->first();
        if (!$adminKomunitas) {
            $adminKomunitas = new Pengguna([
                'nip' => '199001012026011002',
                'nama_lengkap' => 'Admin Komunitas JF Kesehatan',
                'peran' => 'admin_komunitas',
                'rumpun_jabatan' => 'JF',
                'status' => 'aktif',
            ]);
            $adminKomunitas->acakKataSandi()->save();
        }

        PenggunaPeran::firstOrCreate([
            'pengguna_id' => $adminKomunitas->pengguna_id,
            'peran' => 'admin_komunitas',
        ]);

        $adminBkpsdm = Pengguna::where('peran', 'admin_bkpsdm')->first();
        $bkpsdmId = $adminBkpsdm ? $adminBkpsdm->pengguna_id : $adminKomunitas->pengguna_id;

        $komunitas = Komunitas::updateOrCreate(
            ['nama_komunitas' => 'Komunitas JF Kesehatan'],
            [
                'dibuat_oleh_pengguna_id' => $bkpsdmId,
                'deskripsi' => 'Komunitas Belajar untuk Jabatan Fungsional Kesehatan',
                'rumpun_jabatan' => 'JF',
                'status' => 'aktif',
            ]
        );

        AdminKomunitas::firstOrCreate([
            'komunitas_id' => $komunitas->komunitas_id,
            'pengguna_id' => $adminKomunitas->pengguna_id,
        ]);
    }
}
