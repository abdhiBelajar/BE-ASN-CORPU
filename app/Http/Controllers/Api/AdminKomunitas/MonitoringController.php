<?php

namespace App\Http\Controllers\Api\AdminKomunitas;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MonitoringController extends Controller
{
    private function isPembelajaranAdmin($user, $pembelajaran_id) {
        $pembelajaran = \App\Models\Pembelajaran::find($pembelajaran_id);
        if (!$pembelajaran) return false;

        return $user->bisaMengelolaKomunitas($pembelajaran->komunitas_id);
    }

    public function index(Request $request, $pembelajaran_id)
    {
        if (!$this->isPembelajaranAdmin($request->user(), $pembelajaran_id)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $peserta = \App\Models\PendaftaranPembelajaran::where('pembelajaran_id', $pembelajaran_id)
                        ->with(['pengguna' => function($q) {
                            $q->select('pengguna_id', 'nama_lengkap', 'nip', 'rumpun_jabatan', 'unit_kerja', 'jabatan');
                        }])
                        ->get();

        return response()->json([
            'message' => 'Daftar peserta berhasil diambil',
            'data' => $peserta
        ]);
    }

    public function laporanProgress(Request $request)
    {
        $user = $request->user();

        $komunitasIds = $user->komunitasKelolaIds();
        $pembelajaranList = \App\Models\Pembelajaran::whereIn('komunitas_id', $komunitasIds)
            ->select('pembelajaran_id', 'judul_pembelajaran', 'kategori', 'status')
            ->get();
        $pembelajaranIds = $pembelajaranList->pluck('pembelajaran_id');

        // Global stats for this admin's community
        $allPendaftaran = \App\Models\PendaftaranPembelajaran::whereIn('pembelajaran_id', $pembelajaranIds)->get();
        $pendaftaranIds = $allPendaftaran->pluck('pendaftaran_id');
        $totalPeserta = $allPendaftaran->unique('pengguna_id')->count();
        $rataRataProgres = $allPendaftaran->count() > 0 ? round($allPendaftaran->avg('persentase_progres') ?? 0, 1) : 0;
        $lulusPostTest = $allPendaftaran->whereIn('status_pendaftaran', ['lulus', 'selesai'])->count();
        $sertifikatTerbit = \App\Models\Sertifikat::whereIn('pendaftaran_id', $pendaftaranIds)->count();

        // Query with filters
        $query = \App\Models\PendaftaranPembelajaran::whereIn('pembelajaran_id', $pembelajaranIds)
            ->with([
                'pengguna:pengguna_id,nama_lengkap,nip,rumpun_jabatan,unit_kerja,jabatan',
                'pembelajaran:pembelajaran_id,judul_pembelajaran,kategori',
                'ulasan'
            ]);

        if ($request->filled('pembelajaran_id') && $request->pembelajaran_id !== 'all') {
            $query->where('pembelajaran_id', $request->pembelajaran_id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status_pendaftaran', $request->status);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->whereHas('pengguna', function($q) use ($s) {
                $q->where('nama_lengkap', 'like', "%{$s}%")
                  ->orWhere('nip', 'like', "%{$s}%");
            });
        }

        $pendaftaran = $query->latest('terdaftar_pada')->get();

        $pesertaData = $pendaftaran->map(function($p) {
            $latestPostTest = \App\Models\RiwayatPostTest::where('pendaftaran_id', $p->pendaftaran_id)
                ->latest('dikirim_pada')
                ->first();

            return [
                'pendaftaran_id' => $p->pendaftaran_id,
                'pengguna_id' => $p->pengguna_id,
                'nama' => $p->pengguna->nama_lengkap ?? 'Peserta',
                'nip' => $p->pengguna->nip ?? '-',
                'rumpun_jabatan' => $p->pengguna->rumpun_jabatan ?? '-',
                'unit_kerja' => $p->pengguna->unit_kerja ?? 'Pemerintah Kabupaten Buleleng',
                'judul_pembelajaran' => $p->pembelajaran->judul_pembelajaran ?? '-',
                'progres' => round($p->persentase_progres, 1),
                'nilai_post_test' => $latestPostTest ? $latestPostTest->nilai : null,
                'status' => $p->status_pendaftaran,
                'terdaftar_pada' => $p->terdaftar_pada,
                'ulasan' => $p->ulasan ? [
                    'ulasan_id' => $p->ulasan->ulasan_id,
                    'skor_rating' => (int) $p->ulasan->skor_rating,
                    'teks_ulasan' => $p->ulasan->teks_ulasan,
                    'dikirim_pada' => $p->ulasan->dikirim_pada,
                ] : null,
            ];
        });

        return response()->json([
            'message' => 'Laporan progress berhasil diambil',
            'data' => [
                'stats' => [
                    'total_peserta' => $totalPeserta,
                    'rata_rata_progres' => round($rataRataProgres, 1),
                    'lulus_post_test' => $lulusPostTest,
                    'sertifikat_terbit' => $sertifikatTerbit,
                ],
                'peserta' => $pesertaData,
                'pembelajaran_list' => $pembelajaranList
            ]
        ]);
    }
}

