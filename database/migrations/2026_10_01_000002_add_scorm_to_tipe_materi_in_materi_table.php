<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE `materi` MODIFY COLUMN `tipe_materi` ENUM('pdf', 'video_embed', 'h5p', 'scorm') NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE `materi` MODIFY COLUMN `tipe_materi` ENUM('pdf', 'video_embed', 'h5p') NOT NULL");
    }
};
