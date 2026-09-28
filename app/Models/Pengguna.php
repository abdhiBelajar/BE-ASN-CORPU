<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pengguna extends Authenticatable
{
    use HasApiTokens, SoftDeletes;

    protected $table = 'pengguna';
    protected $primaryKey = 'pengguna_id';
    
    const CREATED_AT = 'dibuat_pada';
    const UPDATED_AT = 'diperbarui_pada';
    
    protected $guarded = [];

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
}
