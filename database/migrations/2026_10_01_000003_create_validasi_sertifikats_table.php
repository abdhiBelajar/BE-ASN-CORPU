<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tambahkan kolom verification_code ke tabel sertifikat jika belum ada
        if (!Schema::hasColumn('sertifikat', 'verification_code')) {
            Schema::table('sertifikat', function (Blueprint $table) {
                $table->string('verification_code', 64)->nullable()->unique()->after('nomor_sertifikat');
            });
        }

        // Isi verification_code untuk data sertifikat yang sudah ada
        $existing = DB::table('sertifikat')->whereNull('verification_code')->get();
        foreach ($existing as $item) {
            DB::table('sertifikat')
                ->where('sertifikat_id', $item->sertifikat_id)
                ->update([
                    'verification_code' => Str::random(24) . dechex(time()) . Str::random(8)
                ]);
        }

        // 2. Buat tabel validasi_sertifikat untuk mencatat pemindaian QR
        if (!Schema::hasTable('validasi_sertifikat')) {
            Schema::create('validasi_sertifikat', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sertifikat_id');
                $table->string('nomor_sertifikat', 100);
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('perangkat', 50)->nullable();
                $table->enum('status_validasi', ['valid', 'tidak_valid', 'kedaluwarsa'])->default('valid');
                $table->timestamp('scanned_at')->useCurrent();
                $table->timestamps();

                $table->foreign('sertifikat_id')->references('sertifikat_id')->on('sertifikat')->onDelete('cascade');
                $table->index(['sertifikat_id', 'scanned_at']);
                $table->index('nomor_sertifikat');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('validasi_sertifikat');

        if (Schema::hasColumn('sertifikat', 'verification_code')) {
            Schema::table('sertifikat', function (Blueprint $table) {
                $table->dropColumn('verification_code');
            });
        }
    }
};
