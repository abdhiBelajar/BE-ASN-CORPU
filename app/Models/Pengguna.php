<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class Pengguna extends Authenticatable
{
    use HasApiTokens, SoftDeletes;

    protected $table = 'pengguna';
    protected $primaryKey = 'pengguna_id';

    const CREATED_AT = 'dibuat_pada';
    const UPDATED_AT = 'diperbarui_pada';

    protected $guarded = [];

    protected $casts = [
        'kata_sandi_diatur_pada' => 'datetime',
    ];

    protected $appends = [
        'sudah_aktivasi',
    ];

    public function getSudahAktivasiAttribute(): bool
    {
        return !is_null($this->kata_sandi_diatur_pada);
    }

    /** Set sandi acak yang tidak diketahui siapa pun + tandai belum aktivasi. Belum di-save. */
    public function acakKataSandi(): static
    {
        $this->kata_sandi_hash = Hash::make(Str::random(48));
        $this->kata_sandi_diatur_pada = null;
        return $this;
    }

    /** Sandi buatan pengguna sendiri (dipanggil setelah OTP valid). Belum di-save. */
    public function setKataSandiPengguna(string $plain): static
    {
        $this->kata_sandi_hash = Hash::make($plain);
        $this->kata_sandi_diatur_pada = now();
        return $this;
    }

    /** Aturan kata sandi baru: satu-satunya tempat kebijakan didefinisikan. */
    public static function aturanKataSandi(?string $nip = null): array
    {
        return [
            'required', 'string', 'confirmed',
            Password::min(8)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols(),
            function ($attr, $value, $fail) use ($nip) {
                if ($nip && (str_contains($value, $nip) || str_contains($value, substr($nip, -8)))) {
                    $fail('Kata sandi tidak boleh mengandung NIP Anda.');
                }
            },
        ];
    }

    protected $hidden = [
        'kata_sandi_hash',
        'remember_token',
    ];

    /**
     * Get the password for the user.
     *
     * @return string
     */
    public function getAuthIdentifierName()
    {
        return 'nip';
    }

    /**
     * Get the password for the user.
     *
     * @return string
     */
    public function getAuthPasswordName()
    {
        return 'kata_sandi_hash';
    }

    public function getAuthPassword()
    {
        return $this->kata_sandi_hash;
    }

    public function komunitas()
    {
        return $this->belongsToMany(Komunitas::class, 'komunitas_pengguna', 'pengguna_id', 'komunitas_id')
            ->withPivot('bergabung_pada');
    }

    public function daftarPeran()
    {
        return $this->hasMany(\App\Models\PenggunaPeran::class, 'pengguna_id', 'pengguna_id');
    }

    public function getRolesListAttribute(): array
    {
        $roles = $this->daftarPeran()->pluck('peran')->toArray();
        if (empty($roles) && !empty($this->peran)) {
            return [$this->peran];
        }
        return !empty($roles) ? array_values(array_unique($roles)) : ['peserta'];
    }

    public function verifications()
    {
        return $this->hasMany(\App\Models\Verification::class, 'user_id', 'pengguna_id');
    }
}
