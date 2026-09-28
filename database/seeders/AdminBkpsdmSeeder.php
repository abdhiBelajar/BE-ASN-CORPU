<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AdminBkpsdmSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\Pengguna::updateOrCreate(
            ['nip' => 'root'],
            [
                'nama_lengkap' => 'Super Admin BKPSDM',
                'kata_sandi_hash' => \Illuminate\Support\Facades\Hash::make('Ppir00tlm5123!'),
                'peran' => 'admin_bkpsdm',
                'jabatan' => 'Administrator Sistem',
                'unit_kerja' => 'BKPSDM Kabupaten Buleleng',
                'status' => 'aktif'
            ]
        );
    }
}
