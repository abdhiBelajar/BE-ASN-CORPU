<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Jalankan seeder berurutan: AdminBkpsdm harus lebih dulu
        // agar AdminKomunitasSeeder bisa mengambil data admin_bkpsdm yang sudah ada
        $this->call([
            AdminBkpsdmSeeder::class,
            KomunitasUmumSeeder::class,
            AdminKomunitasSeeder::class,
            PegawaiSimpegSeeder::class,
        ]);
    }
}

