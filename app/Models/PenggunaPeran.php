<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PenggunaPeran extends Model
{
    protected $table = 'pengguna_peran';
    protected $primaryKey = 'pengguna_peran_id';

    const CREATED_AT = 'dibuat_pada';
    const UPDATED_AT = null;

    protected $guarded = [];

    public function pengguna()
    {
        return $this->belongsTo(Pengguna::class, 'pengguna_id', 'pengguna_id');
    }

    public function komunitas()
    {
        return $this->belongsTo(Komunitas::class, 'komunitas_id', 'komunitas_id');
    }
}
