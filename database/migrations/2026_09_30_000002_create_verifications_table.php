<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('pengguna', 'pengguna_id')
                ->cascadeOnDelete();

            $table->uuid('unique_id')
                ->unique();

            $table->string('otp');

            $table->enum('type', [
                'register',
                'reset_password',
            ]);

            $table->enum('send_via', [
                'email',
            ])->default('email');

            $table->unsignedTinyInteger('resent')
                ->default(0);

            $table->unsignedTinyInteger('attempts')
                ->default(0);

            $table->enum('status', [
                'active',
                'valid',
                'invalid',
                'used',
            ])->default('active');

            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index([
                'user_id',
                'type',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verifications');
    }
};
