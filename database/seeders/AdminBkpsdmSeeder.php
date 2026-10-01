<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Pengguna;
use App\Models\PenggunaPeran;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminBkpsdmSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $nip = env('SUPERADMIN_NIP') ?: 'admin123';
        $email = env('SUPERADMIN_EMAIL');
        $initialPassword = env('SUPERADMIN_INITIAL_PASSWORD') ?: 'Ppir00tlm5123!';

        // Cari akun admin_bkpsdm yang sudah ada di database
        $user = Pengguna::where('nip', $nip)
            ->orWhere('nip', 'admin123')
            ->orWhere('peran', 'admin_bkpsdm')
            ->first();

        if (!$user) {
            $user = Pengguna::create([
                'nip' => $nip,
                'nama_lengkap' => 'Super Admin BKPSDM',
                'email' => $email ?: null,
                'kata_sandi_hash' => Hash::make($initialPassword),
                'kata_sandi_diatur_pada' => now(),
                'peran' => 'admin_bkpsdm',
                'jabatan' => 'Administrator Sistem',
                'unit_kerja' => 'BKPSDM Kabupaten Buleleng',
                'status' => 'aktif',
            ]);

            PenggunaPeran::firstOrCreate([
                'pengguna_id' => $user->pengguna_id,
                'peran' => 'admin_bkpsdm',
            ]);

            if ($this->command) {
                $this->command->info("Akun Super Admin ({$user->nip}) berhasil dibuat dengan kata sandi: {$initialPassword}");
            }
        } else {
            // Update kata sandi ke kata sandi yang diminta
            $user->update([
                'kata_sandi_hash' => Hash::make($initialPassword),
                'kata_sandi_diatur_pada' => now(),
                'status' => 'aktif',
            ]);

            PenggunaPeran::firstOrCreate([
                'pengguna_id' => $user->pengguna_id,
                'peran' => 'admin_bkpsdm',
            ]);

            if ($this->command) {
                $this->command->info("Kata sandi akun Super Admin ({$user->nip}) berhasil diperbarui menjadi: {$initialPassword}");
            }
        }

        // Pastikan akun NIP 'root' juga tersedia/disinkronkan agar bisa login dengan NIP 'admin123' maupun 'root'
        $rootUser = Pengguna::where('nip', 'root')->first();
        if (!$rootUser) {
            $rootUser = Pengguna::create([
                'nip' => 'root',
                'nama_lengkap' => 'Super Admin BKPSDM (Root)',
                'email' => $email ?: null,
                'kata_sandi_hash' => Hash::make($initialPassword),
                'kata_sandi_diatur_pada' => now(),
                'peran' => 'admin_bkpsdm',
                'jabatan' => 'Administrator Sistem',
                'unit_kerja' => 'BKPSDM Kabupaten Buleleng',
                'status' => 'aktif',
            ]);

            PenggunaPeran::firstOrCreate([
                'pengguna_id' => $rootUser->pengguna_id,
                'peran' => 'admin_bkpsdm',
            ]);
        } else {
            $rootUser->update([
                'kata_sandi_hash' => Hash::make($initialPassword),
                'kata_sandi_diatur_pada' => now(),
                'status' => 'aktif',
            ]);
        }
    }
}
