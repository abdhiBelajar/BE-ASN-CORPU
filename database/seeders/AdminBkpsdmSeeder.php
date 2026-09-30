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
        $nip = env('SUPERADMIN_NIP', 'root');
        $email = env('SUPERADMIN_EMAIL');
        $initialPassword = env('SUPERADMIN_INITIAL_PASSWORD');

        $user = Pengguna::where('nip', $nip)->first();

        if (!$user) {
            $isGenerated = false;
            if (empty($initialPassword)) {
                $initialPassword = Str::random(24);
                $isGenerated = true;
            }

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
                if ($isGenerated) {
                    $this->command->warn("==================================================");
                    $this->command->warn("Akun Super Admin ({$nip}) berhasil dibuat!");
                    $this->command->warn("Kata sandi awal: {$initialPassword}");
                    $this->command->warn("PERINGATAN: Simpan kata sandi ini sekarang. Sandi tidak akan ditampilkan lagi.");
                    $this->command->warn("==================================================");
                } else {
                    $this->command->info("Akun Super Admin ({$nip}) berhasil dibuat.");
                }
            }
        } else {
            PenggunaPeran::firstOrCreate([
                'pengguna_id' => $user->pengguna_id,
                'peran' => 'admin_bkpsdm',
            ]);
            if ($this->command) {
                $this->command->info("Akun Super Admin ({$nip}) sudah ada, kata sandi dipertahankan.");
            }
        }

        if ($this->command) {
            $this->command->warn("CATATAN KEAMANAN: Kata sandi bawaan lama 'Ppir00tlm5123!' dianggap telah bocor dan harus diganti di seluruh lingkungan.");
        }
    }
}
