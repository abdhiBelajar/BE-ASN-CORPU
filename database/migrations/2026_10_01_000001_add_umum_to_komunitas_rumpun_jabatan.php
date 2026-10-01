<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Hanya tabel komunitas. JANGAN ubah kolom pengguna.rumpun_jabatan.
        DB::statement("ALTER TABLE komunitas MODIFY COLUMN rumpun_jabatan ENUM('JPT','JA','JF','JP','UMUM') NOT NULL");
    }

    public function down(): void
    {
        // PERINGATAN: Rollback menghapus Komunitas Umum beserta data di dalamnya secara permanen.
        // Pindahkan dulu data UMUM agar ALTER tidak gagal, lalu kembalikan ENUM.
        DB::table('komunitas')->where('rumpun_jabatan', 'UMUM')->delete();
        DB::statement("ALTER TABLE komunitas MODIFY COLUMN rumpun_jabatan ENUM('JPT','JA','JF','JP') NOT NULL");
    }
};
