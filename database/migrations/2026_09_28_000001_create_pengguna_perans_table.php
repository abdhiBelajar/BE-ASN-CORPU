<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pengguna_peran', function (Blueprint $table) {
            $table->id('pengguna_peran_id');
            $table->unsignedBigInteger('pengguna_id');
            $table->enum('peran', ['admin_bkpsdm', 'admin_komunitas', 'peserta']);
            $table->unsignedBigInteger('komunitas_id')->nullable()->comment('Diisi jika peran = admin_komunitas');
            $table->timestamp('dibuat_pada')->useCurrent();

            // Foreign keys
            $table->foreign('pengguna_id')->references('pengguna_id')->on('pengguna')->onDelete('cascade');
            $table->foreign('komunitas_id')->references('komunitas_id')->on('komunitas')->onDelete('set null');

            // Unique constraint agar satu pengguna tidak memiliki peran yang sama berulang kali
            $table->unique(['pengguna_id', 'peran'], 'unique_pengguna_peran');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pengguna_peran');
    }
};
