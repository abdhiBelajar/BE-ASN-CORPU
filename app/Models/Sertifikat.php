<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sertifikat extends Model
{
    protected $table = 'sertifikat';
    protected $primaryKey = 'sertifikat_id';
    
    const CREATED_AT = 'dibuat_pada';
    const UPDATED_AT = null;
    
    protected $guarded = [];

    public function pendaftaran()
    {
        return $this->belongsTo(PendaftaranPembelajaran::class, 'pendaftaran_id', 'pendaftaran_id');
    }

    public function validasiSertifikats()
    {
        return $this->hasMany(ValidasiSertifikat::class, 'sertifikat_id', 'sertifikat_id');
    }
}
