<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Modul extends Model
{
    protected $table = 'modul';
    protected $primaryKey = 'modul_id';
    
    const CREATED_AT = 'dibuat_pada';
    const UPDATED_AT = null;
    
    protected $guarded = [];

    protected $appends = ['deskripsi', 'thumbnail_url'];

    public function getThumbnailUrlAttribute()
    {
        if (!empty($this->attributes['thumbnail'])) {
            if (filter_var($this->attributes['thumbnail'], FILTER_VALIDATE_URL)) {
                return $this->attributes['thumbnail'];
            }
            $clean = ltrim($this->attributes['thumbnail'], '/');
            if (str_starts_with($clean, 'storage/')) {
                $clean = substr($clean, 8);
            }
            return asset('storage/' . $clean);
        }
        return null;
    }

    public function getDeskripsiAttribute()
    {
        return $this->attributes['gambaran_umum'] ?? null;
    }

    public function setDeskripsiAttribute($value)
    {
        $this->attributes['gambaran_umum'] = $value;
    }

    public function pembelajaran()
    {
        return $this->belongsTo(Pembelajaran::class, 'pembelajaran_id', 'pembelajaran_id');
    }

    public function materi()
    {
        return $this->hasMany(Materi::class, 'modul_id', 'modul_id')->orderBy('urutan', 'asc');
    }

    public function materis() {
        return $this->hasMany(Materi::class, 'modul_id', 'modul_id')->orderBy('urutan');
    }

    public function kuis()
    {
        return $this->hasOne(Kuis::class, 'modul_id', 'modul_id')->where(function($q) {
            $q->where('tipe_kuis', 'evaluasi_modul')->orWhereNull('tipe_kuis');
        });
    }

    public function kuisBerbobot()
    {
        return $this->hasOne(Kuis::class, 'modul_id', 'modul_id')->where('tipe_kuis', 'kuis_berbobot');
    }

    public function semuaKuis()
    {
        return $this->hasMany(Kuis::class, 'modul_id', 'modul_id');
    }

    public function progresModul()
    {
        return $this->hasMany(ProgresModul::class, 'modul_id', 'modul_id');
    }
}
