<?php

namespace App\Http\Controllers\Api\AdminKomunitas;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PembelajaranJpController extends Controller
{
    private function isPembelajaranAdmin($user, $pembelajaran_id) {
        $pembelajaran = \App\Models\Pembelajaran::find($pembelajaran_id);
        if (!$pembelajaran) return false;

        return $user->bisaMengelolaPembelajaran($pembelajaran);
    }

    public function store(Request $request, $pembelajaran_id)
    {
        if (!$this->isPembelajaranAdmin($request->user(), $pembelajaran_id)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $pembelajaran = \App\Models\Pembelajaran::find($pembelajaran_id);
        if ($pembelajaran && $pembelajaran->status === 'dipublikasikan') {
            $pembelajaran->update(['status' => 'draft']);
        }

        $request->validate([
            'jenis_pelatihan' => 'required|string|in:formal,bimtek,coaching,mentoring',
        ]);

        // Hitung ulang dari semua modul
        $totalDurasi = \App\Models\Modul::where('pembelajaran_id', $pembelajaran_id)->sum('durasi_total_menit');
        $totalJp = \App\Models\Modul::where('pembelajaran_id', $pembelajaran_id)->sum('jp_modul');

        $pembelajaranJp = \App\Models\PembelajaranJp::updateOrCreate(
            ['pembelajaran_id' => $pembelajaran_id],
            [
                'jenis_pelatihan' => $request->jenis_pelatihan,
                'durasi_menit'    => $totalDurasi,
                'jp_dihitung_sistem' => $totalJp,
            ]
        );

        return response()->json([
            'message' => 'Data JP berhasil disimpan',
            'data' => $pembelajaranJp
        ]);
    }

}