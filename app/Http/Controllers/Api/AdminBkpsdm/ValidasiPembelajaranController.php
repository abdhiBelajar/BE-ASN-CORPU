<?php

namespace App\Http\Controllers\Api\AdminBkpsdm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ValidasiPembelajaranController extends Controller
{
    public function index()
    {
        $pembelajaran = \App\Models\Pembelajaran::where('status', 'menunggu_approval')
            ->with(['perancang', 'komunitas', 'pembelajaranJp', 'moduls'])
            ->latest('pembelajaran_id')
            ->get();

        $pembelajaran->each(function ($item) {
            $totalJpModul = (float) $item->moduls->sum('jp_modul');
            $jpRecord = $item->pembelajaranJp->first();
            $item->total_jp = $jpRecord && (float) $jpRecord->jp_dihitung_sistem > 0
                ? (float) $jpRecord->jp_dihitung_sistem
                : $totalJpModul;
        });

        return response()->json([
            'message' => 'Daftar Pembelajaran Menunggu Approval',
            'data' => $pembelajaran
        ]);
    }

    public function store(\Illuminate\Http\Request $request, string $pembelajaran_id)
    {
        $request->validate([
            'status_validasi' => 'required|in:disetujui,ditolak',
            'catatan' => 'nullable|string',
        ]);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $pembelajaran_id) {
            $pembelajaran = \App\Models\Pembelajaran::where('status', 'menunggu_approval')
                ->lockForUpdate()
                ->findOrFail($pembelajaran_id);

            // Validasi & Verifikasi JP jika disetujui (selalu mengikuti JP yang diisi Admin Komunitas)
            if ($request->status_validasi === 'disetujui') {
                $jp = \App\Models\PembelajaranJp::where('pembelajaran_id', $pembelajaran->pembelajaran_id)->first();
                $totalJpModul = (float) \App\Models\Modul::where('pembelajaran_id', $pembelajaran->pembelajaran_id)->sum('jp_modul');
                
                $finalJp = $jp && (float) $jp->jp_dihitung_sistem > 0 
                    ? (float) $jp->jp_dihitung_sistem 
                    : $totalJpModul;

                if ($jp) {
                    $jp->update([
                        'jp_final' => $finalJp,
                        'diverifikasi_oleh_bkpsdm' => true,
                        'diverifikasi_pada' => now()
                    ]);
                } else {
                    \App\Models\PembelajaranJp::create([
                        'pembelajaran_id' => $pembelajaran->pembelajaran_id,
                        'jenis_pelatihan' => 'formal',
                        'durasi_menit' => 0,
                        'jp_dihitung_sistem' => $finalJp,
                        'jp_final' => $finalJp,
                        'diverifikasi_oleh_bkpsdm' => true,
                        'diverifikasi_pada' => now()
                    ]);
                }
            }

            // Rekam Validasi
            $validasi = \App\Models\ValidasiPembelajaran::create([
                'pembelajaran_id' => $pembelajaran->pembelajaran_id,
                'divalidasi_oleh_pengguna_id' => $request->user()->pengguna_id,
                'status_validasi' => $request->status_validasi,
                'catatan' => $request->catatan,
                'divalidasi_pada' => now()
            ]);

            // Update status Pembelajaran
            if ($request->status_validasi === 'disetujui') {
                $pembelajaran->update([
                    'status' => 'dipublikasikan',
                    'dipublikasikan_pada' => now()
                ]);

                // Reset status kelulusan peserta jika ada modul/soal post-test yang diedit dan divalidasi
                \App\Services\CourseProgressService::resetCourseCompletionAfterApproval($pembelajaran->pembelajaran_id);
            } else {
                $pembelajaran->update([
                    'status' => 'ditolak'
                ]);
            }

            return response()->json([
                'message' => 'Validasi berhasil disimpan',
                'data' => [
                    'pembelajaran' => $pembelajaran,
                    'validasi' => $validasi
                ]
            ]);
        });
    }

    public function show(string $id)
    {
        $pembelajaran = \App\Models\Pembelajaran::with([
            'komunitas',
            'perancang',
            'pembelajaranJp',
            'moduls.materis',
            'moduls.kuis',
            'postTests'
        ])->findOrFail($id);

        $totalJpModul = (float) $pembelajaran->moduls->sum('jp_modul');
        $jpRecord = $pembelajaran->pembelajaranJp->first();

        $pembelajaran->total_jp = $jpRecord && (float) $jpRecord->jp_dihitung_sistem > 0
            ? (float) $jpRecord->jp_dihitung_sistem
            : $totalJpModul;

        $pendaftaranIds = \App\Models\PendaftaranPembelajaran::where('pembelajaran_id', $id)->pluck('pendaftaran_id');
        $ulasanList = \App\Models\UlasanPembelajaran::whereIn('pendaftaran_id', $pendaftaranIds)
            ->with(['pendaftaran.pengguna:pengguna_id,nama_lengkap,nip,unit_kerja'])
            ->latest('dikirim_pada')
            ->get();

        $totalUlasan = $ulasanList->count();
        $avgRating = $totalUlasan > 0 ? round($ulasanList->avg('skor_rating'), 1) : 0;

        $pembelajaran->statistik_ulasan = [
            'total' => $totalUlasan,
            'rata_rata' => $avgRating,
            'daftar' => $ulasanList->map(function ($u) {
                return [
                    'ulasan_id' => $u->ulasan_id,
                    'skor_rating' => (int) $u->skor_rating,
                    'teks_ulasan' => $u->teks_ulasan,
                    'dikirim_pada' => $u->dikirim_pada,
                    'nama_peserta' => $u->pendaftaran?->pengguna?->nama_lengkap ?? 'Peserta',
                    'nip' => $u->pendaftaran?->pengguna?->nip ?? '-',
                    'unit_kerja' => $u->pendaftaran?->pengguna?->unit_kerja ?? '-',
                ];
            })
        ];

        return response()->json([
            'message' => 'Detail pembelajaran berhasil diambil',
            'data' => $pembelajaran
        ]);
    }
}
