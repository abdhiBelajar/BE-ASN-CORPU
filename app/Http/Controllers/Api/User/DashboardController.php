<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PendaftaranPembelajaran;
use App\Models\Pembelajaran;
use App\Models\Sertifikat;
use App\Models\PembelajaranJp;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (empty($user->jabatan) || empty($user->unit_kerja)) {
            try {
                $simpegApi = app(\App\Services\SimpegApiService::class);
                $pegawai = $simpegApi->getPegawaiByNip($user->nip);
                if ($pegawai) {
                    $user->update([
                        'nama_lengkap' => $user->nama_lengkap ?: ($pegawai['nama_lengkap'] ?? $user->nama_lengkap),
                        'jabatan' => $pegawai['jabatan'] ?? $user->jabatan,
                        'rumpun_jabatan' => $pegawai['rumpun_jabatan'] ?? $user->rumpun_jabatan,
                        'unit_kerja' => $pegawai['unit_kerja'] ?? $user->unit_kerja,
                    ]);
                    $user->refresh();
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Dashboard auto-sync simpeg failed: ' . $e->getMessage());
            }
        }
        $penggunaId = $user->pengguna_id;

        // Semua pendaftaran user ini
        $pendaftaran = PendaftaranPembelajaran::where('pengguna_id', $penggunaId)
            ->with(['pembelajaran' => function($q) {
                $q->withCount('modul')->with('pembelajaranJp');
            }])
            ->get();

        // Sinkronisasi otomatis setiap progres pendaftaran user terhadap modul/materi terbaru
        foreach ($pendaftaran as $p) {
            \App\Services\CourseProgressService::syncUserProgress($p);
        }

        // Ambil ulang data pendaftaran setelah sinkronisasi
        $pendaftaran = PendaftaranPembelajaran::where('pengguna_id', $penggunaId)
            ->with(['pembelajaran' => function($q) {
                $q->withCount('modul')->with('pembelajaranJp');
            }])
            ->get();

        // Pelatihan aktif: pelatihan yang belum selesai 100% dan bukan status selesai/lulus
        $aktif = $pendaftaran->filter(function($p) {
            return (float)$p->persentase_progres < 100 && !in_array($p->status_pendaftaran, ['selesai', 'lulus']);
        })->count();

        // Pelatihan selesai: status selesai/lulus atau progres 100%
        $selesai = $pendaftaran->filter(function($p) {
            return (float)$p->persentase_progres >= 100 || in_array($p->status_pendaftaran, ['selesai', 'lulus']);
        })->count();

        // Total JPL dari pelatihan yang selesai/lulus
        $pembelajaranIdsSelesai = $pendaftaran->filter(function($p) {
            return (float)$p->persentase_progres >= 100 || in_array($p->status_pendaftaran, ['selesai', 'lulus']);
        })->pluck('pembelajaran_id');
        $totalJpl = PembelajaranJp::whereIn('pembelajaran_id', $pembelajaranIdsSelesai)->sum('jp_final');

        // Sertifikat
        $sertifikat = Sertifikat::whereHas('pendaftaran', function($q) use ($penggunaId) {
            $q->where('pengguna_id', $penggunaId);
        })->count();

        // Current Course: Pelatihan yang sedang dijalani (< 100%) dengan progress paling sedikit diantara kursus yang lain
        $currentCourseReg = PendaftaranPembelajaran::where('pengguna_id', $penggunaId)
            ->where('persentase_progres', '<', 100)
            ->whereNotIn('status_pendaftaran', ['selesai', 'lulus'])
            ->with([
                'pembelajaran' => function($q) {
                    $q->with([
                        'modul' => function($mq) {
                            $mq->orderBy('urutan', 'asc')->with(['materi', 'kuis']);
                        },
                        'pembelajaranJp'
                    ])->withCount('modul');
                }
            ])
            ->orderBy('persentase_progres', 'asc') // Menampilkan kursus dengan progres paling sedikit
            ->orderBy('terdaftar_pada', 'desc')
            ->first();

        $currentCourse = null;
        if ($currentCourseReg && $currentCourseReg->pembelajaran) {
            $pemb = $currentCourseReg->pembelajaran;
            $jpCurrent = $pemb->pembelajaranJp->first();

            // Cari modul yang belum selesai untuk teks "SELANJUTNYA"
            $progresMateriIds = \App\Models\ProgresMateri::where('pendaftaran_id', $currentCourseReg->pendaftaran_id)
                ->where('apakah_selesai', true)
                ->pluck('materi_id')
                ->toArray();

            $riwayatKuisIds = \App\Models\RiwayatKuis::where('pendaftaran_id', $currentCourseReg->pendaftaran_id)
                ->where('apakah_lulus', true)
                ->pluck('kuis_id')
                ->toArray();

            $nextModuleTitle = null;
            if ($pemb->modul && $pemb->modul->count() > 0) {
                foreach ($pemb->modul as $m) {
                    $materiBelumSelesai = $m->materi->first(function($mat) use ($progresMateriIds) {
                        return !in_array($mat->materi_id, $progresMateriIds);
                    });

                    $kuisBelumSelesai = $m->kuis && !in_array($m->kuis->kuis_id, $riwayatKuisIds);

                    if ($materiBelumSelesai || $kuisBelumSelesai) {
                        $nextModuleTitle = 'Modul ' . $m->urutan . ' - ' . $m->judul_modul;
                        break;
                    }
                }
            }

            if (!$nextModuleTitle) {
                $firstModul = $pemb->modul ? $pemb->modul->first() : null;
                $nextModuleTitle = $firstModul ? ('Modul ' . $firstModul->urutan . ' - ' . $firstModul->judul_modul) : 'Lanjutkan Pembelajaran';
            }

            // Dapatkan URL thumbnail yang valid
            $thumbnail = $pemb->thumbnail_url;
            if ($thumbnail && !str_starts_with($thumbnail, 'http')) {
                $thumbnail = url($thumbnail);
            }

            $jpl = $jpCurrent ? ($jpCurrent->jp_final ?? $jpCurrent->jp_dihitung_sistem ?? 0) : 0;
            if (!$jpl && $pemb->modul) {
                $jpl = (float)$pemb->modul->sum('jp_modul');
            }
            if (!$jpl) {
                $jpl = 1;
            }

            $currentCourse = [
                'pendaftaran_id' => $currentCourseReg->pendaftaran_id,
                'pembelajaran_id' => $pemb->pembelajaran_id,
                'judul' => $pemb->judul_pembelajaran,
                'kategori' => $pemb->kategori,
                'progress' => round((float)$currentCourseReg->persentase_progres, 0),
                'total_modul' => $pemb->modul_count,
                'jpl' => $jpl,
                'next_module' => $nextModuleTitle,
                'image' => $thumbnail,
                'thumbnail_url' => $thumbnail,
            ];
        }

        // Rekomendasi (Kursus yang dipublikasikan dari komunitas yang diikuti dan belum diikuti)
        $joinedKomunitasIds = $user->komunitasAksesIds();
        $enrolledIds = $pendaftaran->pluck('pembelajaran_id');
        $rekomendasi = [];

        if (!empty($joinedKomunitasIds)) {
            $rekomendasiQuery = Pembelajaran::where('status', 'dipublikasikan')
                ->whereIn('komunitas_id', $joinedKomunitasIds)
                ->whereNotIn('pembelajaran_id', $enrolledIds);

            if (!empty($user->rumpun_jabatan)) {
                $rekomendasiQuery->whereHas('komunitas', function($q) use ($user) {
                    $q->where(function ($sub) use ($user) {
                        $sub->where('rumpun_jabatan', $user->rumpun_jabatan)
                            ->orWhere('rumpun_jabatan', \App\Models\Komunitas::RUMPUN_UMUM);
                    });
                });
            }

            $rekomendasi = $rekomendasiQuery
                ->withCount('modul')
                ->with('pembelajaranJp')
                ->latest('dipublikasikan_pada')
                ->take(3)
                ->get()
                ->map(function ($c) {
                    $jpRek = $c->pembelajaranJp->first();
                    return [
                        'pembelajaran_id' => $c->pembelajaran_id,
                        'judul' => $c->judul_pembelajaran,
                        'jpl' => $jpRek ? ($jpRek->jp_final ?? $jpRek->jp_dihitung_sistem ?? 0) : 0,
                        'total_modul' => $c->modul_count,
                        'image' => $c->thumbnail_url,
                        'thumbnail_url' => $c->thumbnail_url,
                    ];
                });
        }

        // Aktivitas Terakhir
        $activities = [];
        foreach($pendaftaran->sortByDesc('terdaftar_pada')->take(3) as $p) {
            $activities[] = [
                'type' => $p->status_pendaftaran,
                'title' => 'Mendaftar pelatihan ' . ($p->pembelajaran->judul_pembelajaran ?? 'Pelatihan'),
                'time' => $p->terdaftar_pada ? \Carbon\Carbon::parse($p->terdaftar_pada)->diffForHumans() : 'Baru saja'
            ];
        }

        return response()->json([
            'message' => 'Dashboard user berhasil diambil',
            'data' => [
                'user' => [
                    'nama_lengkap' => $user->nama_lengkap,
                    'nip' => $user->nip,
                    'jabatan' => $user->jabatan ?: 'Pegawai ASN',
                    'unit_kerja' => $user->unit_kerja ?: 'Pemerintah Kabupaten Buleleng',
                    'email' => $user->email,
                    'rumpun_jabatan' => $user->rumpun_jabatan ?: 'JP',
                ],
                'stats' => [
                    'aktif' => $aktif,
                    'selesai' => $selesai,
                    'total_jpl' => $totalJpl,
                    'sertifikat' => $sertifikat,
                ],
                'current_course' => $currentCourse,
                'rekomendasi' => $rekomendasi,
                'activities' => $activities
            ]
        ]);
    }
}
