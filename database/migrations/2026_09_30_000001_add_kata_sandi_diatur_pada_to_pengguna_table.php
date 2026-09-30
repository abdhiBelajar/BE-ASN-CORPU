<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('pengguna', function (Blueprint $table) {
            $table->timestamp('kata_sandi_diatur_pada')->nullable()->after('kata_sandi_hash');
        });

        // Akun yang SUDAH ada tetap bisa login sampai command auth:amankan-sandi-lama dijalankan (B9).
        DB::table('pengguna')->update(['kata_sandi_diatur_pada' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pengguna', function (Blueprint $table) {
            $table->dropColumn('kata_sandi_diatur_pada');
        });
    }
};
