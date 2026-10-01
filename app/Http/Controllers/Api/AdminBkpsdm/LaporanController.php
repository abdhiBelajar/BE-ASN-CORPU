<?php

namespace App\Http\Controllers\Api\AdminBkpsdm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LaporanController extends Controller
{
    /**
     * Helper method to get formatted and filtered peserta data
     */
    protected function getPesertaData(Request $request)
    {
        $hasCourseFilter = $request->filled('pembelajaran_id') || $request->filled('komunitas_id') || $request->filled('status_sertifikat');

        $pendaftaranQuery = \App\Models\PendaftaranPembelajaran::with([
            'pengguna',
            'pembelajaran.komunitas',
            'sertifikat',
            'ulasan'
        ]);

        if ($request->filled('search')) {
            $search = $request->search;
            $pendaftaranQuery->whereHas('pengguna', function ($q) use ($search) {
                $q->where('nama_lengkap', 'like', "%{$search}%")
                  ->orWhere('nip', 'like', "%{$search}%");
            });
        }

        if ($request->filled('komunitas_id')) {
            $pendaftaranQuery->whereHas('pembelajaran', function ($q) use ($request) {
                $q->where('komunitas_id', $request->komunitas_id);
            });
        }

        if ($request->filled('pembelajaran_id')) {
            $pendaftaranQuery->where('pembelajaran_id', $request->pembelajaran_id);
        }

        if ($request->filled('status_sertifikat')) {
            $val = strtolower($request->status_sertifikat);
            if (in_array($val, ['1', 'ada', 'true', 'punya', 'terbit'])) {
                $pendaftaranQuery->whereHas('sertifikat');
            } elseif (in_array($val, ['0', 'tidak', 'false', 'belum'])) {
                $pendaftaranQuery->doesntHave('sertifikat');
            }
        }

        if ($request->filled('tanggal_mulai')) {
            $pendaftaranQuery->whereDate('terdaftar_pada', '>=', $request->tanggal_mulai);
        }

        if ($request->filled('tanggal_selesai')) {
            $pendaftaranQuery->whereDate('terdaftar_pada', '<=', $request->tanggal_selesai);
        }

        $pendaftarans = $pendaftaranQuery->latest('pendaftaran_id')->get();

        $items = $pendaftarans->map(function ($p) {
            return [
                'pendaftaran_id' => $p->pendaftaran_id,
                'pengguna_id' => $p->pengguna_id,
                'nama_lengkap' => $p->pengguna?->nama_lengkap ?? '-',
                'nip' => $p->pengguna?->nip ?? '-',
                'jabatan' => $p->pengguna?->jabatan ?? '-',
                'instansi' => $p->pengguna?->instansi ?? '-',
                'unit_kerja' => $p->pengguna?->unit_kerja ?? '-',
                'rumpun_jabatan' => $p->pengguna?->rumpun_jabatan ?? '-',
                'judul_pembelajaran' => $p->pembelajaran?->judul_pembelajaran ?? '-',
                'nama_komunitas' => $p->pembelajaran?->komunitas?->nama_komunitas ?? 'BKPSDM',
                'jenis_pelatihan' => $p->pembelajaran?->kategori ?? 'Pengembangan Kompetensi',
                'progres' => (float) ($p->persentase_progres ?? 0),
                'status_pendaftaran' => $p->status_pendaftaran,
                'tanggal_daftar' => $p->terdaftar_pada,
                'tanggal_selesai' => $p->diselesaikan_pada,
                'has_sertifikat' => !is_null($p->sertifikat),
                'sertifikat' => $p->sertifikat ? [
                    'sertifikat_id' => $p->sertifikat->sertifikat_id,
                    'nomor_sertifikat' => $p->sertifikat->nomor_sertifikat,
                    'file_sertifikat_path' => $p->sertifikat->file_sertifikat_path,
                    'diterbitkan_pada' => $p->sertifikat->diterbitkan_pada,
                    'download_url' => url('/api/admin-bkpsdm/sertifikat/' . $p->sertifikat->sertifikat_id . '/download'),
                ] : null,
                'ulasan' => $p->ulasan ? [
                    'ulasan_id' => $p->ulasan->ulasan_id,
                    'skor_rating' => (int) $p->ulasan->skor_rating,
                    'teks_ulasan' => $p->ulasan->teks_ulasan,
                    'dikirim_pada' => $p->ulasan->dikirim_pada,
                ] : null,
                'pengguna' => $p->pengguna,
                'pembelajaran' => $p->pembelajaran,
            ];
        });

        // If no course/community filter is specified, also append participants with no enrollments
        if (!$hasCourseFilter) {
            $registeredUserIds = $pendaftarans->pluck('pengguna_id')->unique()->toArray();
            $unregisteredQuery = \App\Models\Pengguna::where('peran', 'peserta')
                ->whereNotIn('pengguna_id', $registeredUserIds);

            if ($request->filled('search')) {
                $search = $request->search;
                $unregisteredQuery->where(function ($q) use ($search) {
                    $q->where('nama_lengkap', 'like', "%{$search}%")
                      ->orWhere('nip', 'like', "%{$search}%");
                });
            }

            $unregistered = $unregisteredQuery->latest('pengguna_id')->get();
            foreach ($unregistered as $u) {
                $items->push([
                    'pendaftaran_id' => null,
                    'pengguna_id' => $u->pengguna_id,
                    'nama_lengkap' => $u->nama_lengkap,
                    'nip' => $u->nip ?? '-',
                    'jabatan' => $u->jabatan ?? '-',
                    'instansi' => $u->instansi ?? '-',
                    'unit_kerja' => $u->unit_kerja ?? '-',
                    'rumpun_jabatan' => $u->rumpun_jabatan ?? '-',
                    'judul_pembelajaran' => 'Belum terdaftar',
                    'nama_komunitas' => 'BKPSDM',
                    'jenis_pelatihan' => '-',
                    'progres' => 0,
                    'status_pendaftaran' => 'Belum Mulai',
                    'tanggal_daftar' => null,
                    'tanggal_selesai' => null,
                    'has_sertifikat' => false,
                    'sertifikat' => null,
                    'pengguna' => $u,
                    'pembelajaran' => null,
                ]);
            }
        }

        return $items;
    }

    public function peserta(Request $request)
    {
        $items = $this->getPesertaData($request);

        if ($request->has('page') || $request->has('per_page')) {
            $perPage = (int) $request->input('per_page', 10);
            $page = (int) $request->input('page', 1);
            $slice = $items->slice(($page - 1) * $perPage, $perPage)->values();

            return response()->json([
                'message' => 'Laporan Data Peserta',
                'data' => $slice,
                'meta' => [
                    'current_page' => $page,
                    'last_page' => max(1, (int) ceil($items->count() / $perPage)),
                    'per_page' => $perPage,
                    'total' => $items->count(),
                ]
            ]);
        }

        return response()->json([
            'message' => 'Laporan Data Peserta',
            'data' => $items
        ]);
    }

    public function exportPeserta(Request $request)
    {
        $items = $this->getPesertaData($request);

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="laporan_peserta.csv"',
        ];

        // Defense against Formula Injection (CSV injection)
        $sanitize = function ($value) {
            if ($value === null) return '';
            $str = (string) $value;
            if (preg_match('/^([=+\-@\t\r])/', $str)) {
                return "'" . $str;
            }
            return $str;
        };

        $callback = function () use ($items, $sanitize) {
            $file = fopen('php://output', 'w');
            // Write UTF-8 BOM so Excel opens strings/characters cleanly
            fputs($file, "\xEF\xBB\xBF");

            fputcsv($file, [
                'NIP',
                'Nama Lengkap',
                'Jabatan',
                'Rumpun',
                'Unit Kerja',
                'Pembelajaran',
                'Komunitas',
                'Jenis Pelatihan',
                'Progres (%)',
                'Status',
                'Tanggal Selesai',
                'Nomor Sertifikat'
            ]);

            foreach ($items as $row) {
                fputcsv($file, [
                    $sanitize($row['nip']),
                    $sanitize($row['nama_lengkap']),
                    $sanitize($row['jabatan']),
                    $sanitize($row['rumpun_jabatan']),
                    $sanitize($row['unit_kerja']),
                    $sanitize($row['judul_pembelajaran']),
                    $sanitize($row['nama_komunitas']),
                    $sanitize($row['jenis_pelatihan']),
                    $sanitize($row['progres']),
                    $sanitize($row['status_pendaftaran']),
                    $sanitize($row['tanggal_selesai']),
                    $sanitize($row['sertifikat'] ? $row['sertifikat']['nomor_sertifikat'] : '-'),
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function ulasan(Request $request)
    {
        $query = \App\Models\UlasanPembelajaran::with([
            'pendaftaran.pengguna',
            'pendaftaran.pembelajaran.komunitas'
        ]);

        if ($request->filled('komunitas_id')) {
            $query->whereHas('pendaftaran.pembelajaran', function ($q) use ($request) {
                $q->where('komunitas_id', $request->komunitas_id);
            });
        }

        if ($request->filled('pembelajaran_id')) {
            $query->whereHas('pendaftaran', function ($q) use ($request) {
                $q->where('pembelajaran_id', $request->pembelajaran_id);
            });
        }

        if ($request->filled('skor_rating')) {
            $query->where('skor_rating', $request->skor_rating);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('teks_ulasan', 'like', "%{$search}%")
                  ->orWhereHas('pendaftaran.pengguna', function ($qu) use ($search) {
                      $qu->where('nama_lengkap', 'like', "%{$search}%")
                         ->orWhere('nip', 'like', "%{$search}%");
                  })
                  ->orWhereHas('pendaftaran.pembelajaran', function ($qp) use ($search) {
                      $qp->where('judul_pembelajaran', 'like', "%{$search}%");
                  });
            });
        }

        $allUlasan = $query->latest('dikirim_pada')->get();

        $totalUlasan = $allUlasan->count();
        $avgRating = $totalUlasan > 0 ? round($allUlasan->avg('skor_rating'), 1) : 0;
        $puasCount = $allUlasan->where('skor_rating', '>=', 4)->count();
        $persenPuas = $totalUlasan > 0 ? round(($puasCount / $totalUlasan) * 100) : 0;

        $items = $allUlasan->map(function ($u) {
            return [
                'ulasan_id' => $u->ulasan_id,
                'skor_rating' => (int) $u->skor_rating,
                'teks_ulasan' => $u->teks_ulasan,
                'dikirim_pada' => $u->dikirim_pada,
                'peserta' => [
                    'pengguna_id' => $u->pendaftaran?->pengguna?->pengguna_id,
                    'nama_lengkap' => $u->pendaftaran?->pengguna?->nama_lengkap ?? '-',
                    'nip' => $u->pendaftaran?->pengguna?->nip ?? '-',
                    'unit_kerja' => $u->pendaftaran?->pengguna?->unit_kerja ?? '-',
                    'instansi' => $u->pendaftaran?->pengguna?->instansi ?? '-',
                ],
                'pembelajaran' => [
                    'pembelajaran_id' => $u->pendaftaran?->pembelajaran?->pembelajaran_id,
                    'judul_pembelajaran' => $u->pendaftaran?->pembelajaran?->judul_pembelajaran ?? '-',
                    'kategori' => $u->pendaftaran?->pembelajaran?->kategori ?? '-',
                    'nama_komunitas' => $u->pendaftaran?->pembelajaran?->komunitas?->nama_komunitas ?? 'BKPSDM',
                ]
            ];
        });

        return response()->json([
            'message' => 'Data ulasan berhasil diambil',
            'data' => [
                'stats' => [
                    'total_ulasan' => $totalUlasan,
                    'rata_rata_rating' => $avgRating,
                    'persen_puas' => $persenPuas,
                    'distribusi' => [
                        5 => $allUlasan->where('skor_rating', 5)->count(),
                        4 => $allUlasan->where('skor_rating', 4)->count(),
                        3 => $allUlasan->where('skor_rating', 3)->count(),
                        2 => $allUlasan->where('skor_rating', 2)->count(),
                        1 => $allUlasan->where('skor_rating', 1)->count(),
                    ]
                ],
                'ulasan' => $items
            ]
        ]);
    }
}
