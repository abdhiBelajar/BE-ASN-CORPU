<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ValidasiSertifikat extends Model
{
    protected $table = 'validasi_sertifikat';

    protected $guarded = [];

    protected $casts = [
        'scanned_at' => 'datetime',
    ];

    public function sertifikat()
    {
        return $this->belongsTo(Sertifikat::class, 'sertifikat_id', 'sertifikat_id');
    }
}
