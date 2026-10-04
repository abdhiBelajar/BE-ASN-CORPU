<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Pembelajaran;
use App\Models\PendaftaranPembelajaran;
use App\Models\ProgresMateri;
use App\Models\Materi;
use App\Models\UlasanPembelajaran;

class CourseDetailController extends Controller
{
    public function show(Request $request, $id)
    {
        $user = $request->user();

        // Ambil data pelatihan beserta relasinya
        $pembelajaran = Pembelajaran::with([
            'kategoriKursus',
            'modul' => function ($q) {
                $q->orderBy('urutan', 'asc')->with(['materi.preTest.soalKuis', 'kuis.soalKuis', 'kuisBerbobot.soalKuis', 'semuaKuis.soalKuis']);
            },
            'postTest',
            'pembelajaranJp'
        ])->findOrFail($id);

        // Cek pendaftaran
        $pendaftaran = PendaftaranPembelajaran::where('pengguna_id', $user->pengguna_id)
            ->where('pembelajaran_id', $id)
            ->first();

        $progresMateri = [];
        if ($pendaftaran) {
            \App\Services\CourseProgressService::syncUserProgress($pendaftaran);
            $pendaftaran->refresh();
            $progresMateri = ProgresMateri::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
                ->pluck('apakah_selesai', 'materi_id')
                ->toArray();
        }

        // Map data agar mudah dikonsumsi frontend
        $jpDet = $pembelajaran->pembelajaranJp->first();

        $isCourseLockedReview = $pembelajaran->status !== 'dipublikasikan';

        // Evaluasi locking silabus
        $isPreviousModulePassed = true;
        $mappedModul = $pembelajaran->modul->map(function ($m) use ($progresMateri, $pendaftaran, &$isPreviousModulePassed, $isCourseLockedReview) {
            $isModuleLocked = $isCourseLockedReview || !$isPreviousModulePassed;

            $isPreviousMateriDone = true;
            $allMateriInThisModulDone = true;

            $materiList = $m->materi->map(function ($mat) use ($progresMateri, $pendaftaran, $isModuleLocked, &$isPreviousMateriDone, &$allMateriInThisModulDone, $isCourseLockedReview) {
                $isRead = isset($progresMateri[$mat->materi_id]) && $progresMateri[$mat->materi_id] ? true : false;
                $isOrderLocked = $isModuleLocked || !$isPreviousMateriDone || $isCourseLockedReview;

                $preTest = $mat->preTest;
                $isPreTestDone = false;
                if ($preTest) {
                    $isPreTestDone = $pendaftaran ? \App\Models\RiwayatKuis::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
                        ->where('kuis_id', $preTest->kuis_id)
                        ->exists() : false;
                }

                $isContentLocked = $isCourseLockedReview || $isOrderLocked || ($preTest && !$isPreTestDone);

                if (!$isRead) {
                    $isPreviousMateriDone = false;
                    $allMateriInThisModulDone = false;
                }

                return [
                    'materi_id' => $mat->materi_id,
                    'judul' => $mat->judul_materi,
                    'tipe' => $mat->tipe_materi,
                    'tautan' => ($pendaftaran && !$isContentLocked) ? $mat->tautan_atau_berkas : null,
                    'durasi' => $mat->durasi_menit,
                    'is_read' => $isRead,
                    'is_locked' => $isContentLocked,
                    'pre_test' => $preTest ? [
                        'kuis_id' => $preTest->kuis_id,
                        'judul' => $preTest->judul_kuis,
                        'durasi' => $preTest->durasi_menit,
                        'is_completed' => $isPreTestDone,
                        'is_locked' => $isCourseLockedReview || $isOrderLocked,
                        'tipe_soal_list' => $preTest->soalKuis ? $preTest->soalKuis->pluck('tipe_soal')->unique()->values()->all() : []
                    ] : null
                ];
            });

            $isKuisCompleted = false;
            $isKuisLocked = true;
            if ($m->kuis) {
                $isKuisCompleted = $pendaftaran ? \App\Models\RiwayatKuis::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
                    ->where('kuis_id', $m->kuis->kuis_id)
                    ->where('apakah_lulus', true)
                    ->exists() : false;
                
                // Kuis terbuka hanya jika seluruh materi pada modul ini sudah dibaca
                $isKuisLocked = $isCourseLockedReview || $isModuleLocked || !$allMateriInThisModulDone;
            }

            $kuisBerbobot = $m->kuisBerbobot ?? ($m->semuaKuis ? $m->semuaKuis->where('tipe_kuis', 'kuis_berbobot')->first() : null);
            $isKuisBerbobotCompleted = false;
            $isKuisBerbobotLocked = true;
            if ($kuisBerbobot) {
                $isKuisBerbobotCompleted = $pendaftaran ? \App\Models\RiwayatKuis::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
                    ->where('kuis_id', $kuisBerbobot->kuis_id)
                    ->where('apakah_lulus', true)
                    ->exists() : false;
                
                $isKuisBerbobotLocked = $isCourseLockedReview || $isModuleLocked || !$allMateriInThisModulDone;
            }

            // Status kelulusan modul untuk menentukan apakah modul berikutnya terbuka
            $isModuleQuizPassed = true;
            if ($m->kuis) {
                $isModuleQuizPassed = $isModuleQuizPassed && $isKuisCompleted;
            }
            if ($kuisBerbobot) {
                $isModuleQuizPassed = $isModuleQuizPassed && $isKuisBerbobotCompleted;
            }
            $isPreviousModulePassed = $allMateriInThisModulDone && $isModuleQuizPassed;

            return [
                'modul_id' => $m->modul_id,
                'judul' => $m->judul_modul,
                'urutan' => $m->urutan,
                'is_locked' => $isModuleLocked,
                'materi' => $materiList,
                'kuis' => $m->kuis ? [
                    'kuis_id' => $m->kuis->kuis_id,
                    'judul' => $m->kuis->judul_kuis,
                    'durasi' => $m->kuis->durasi_menit,
                    'is_completed' => $isKuisCompleted,
                    'is_locked' => $isKuisLocked,
                    'tipe_soal_list' => $m->kuis->soalKuis ? $m->kuis->soalKuis->pluck('tipe_soal')->unique()->values()->all() : []
                ] : null,
                'kuis_berbobot' => $kuisBerbobot ? [
                    'kuis_id' => $kuisBerbobot->kuis_id,
                    'judul' => $kuisBerbobot->judul_kuis,
                    'durasi' => $kuisBerbobot->durasi_menit,
                    'is_completed' => $isKuisBerbobotCompleted,
                    'is_locked' => $isKuisBerbobotLocked,
                    'tipe_soal_list' => $kuisBerbobot->soalKuis ? $kuisBerbobot->soalKuis->pluck('tipe_soal')->unique()->values()->all() : []
                ] : null
            ];
        });

        $data = [
            'pembelajaran_id' => $pembelajaran->pembelajaran_id,
            'judul' => $pembelajaran->judul_pembelajaran,
            'deskripsi' => $pembelajaran->deskripsi,
            'kategori_id' => $pembelajaran->kategori_id,
            'kategori' => $pembelajaran->kategori,
            'status' => $pembelajaran->status,
            'is_locked_review' => $isCourseLockedReview,
            'lock_reason' => $isCourseLockedReview ? 'Materi pembelajaran ini sedang dalam pembaruan dan menunggu persetujuan Admin BKPSDM. Seluruh akses materi sementara terkunci.' : null,
            'jpl' => $jpDet ? $jpDet->jp_final : 0,
            'is_enrolled' => $pendaftaran ? true : false,
            'status_pendaftaran' => $pendaftaran->status_pendaftaran ?? null,
            'progress' => $pendaftaran->persentase_progres ?? 0,
            'modul' => $mappedModul,
            'post_test' => $pembelajaran->postTest ? [
                'post_test_id' => $pembelajaran->postTest->post_test_id,
                'judul' => 'Post Test Akhir Pelatihan',
                'durasi' => $pembelajaran->postTest->durasi_menit,
                'is_locked' => $isCourseLockedReview || (($pendaftaran->persentase_progres ?? 0) < 100)
            ] : null
        ];

        return response()->json([
            'message' => 'Detail pelatihan berhasil diambil',
            'data' => $data
        ]);
    }

    public function markMateriAsRead(Request $request, $id, $materi_id)
    {
        $user = $request->user();

        $pembelajaran = Pembelajaran::find($id);
        if ($pembelajaran && $pembelajaran->status !== 'dipublikasikan') {
            return response()->json([
                'message' => 'Materi tidak dapat diselesaikan karena kursus sedang dalam proses peninjauan pembaruan oleh Admin BKPSDM.'
            ], 400);
        }

        $pendaftaran = PendaftaranPembelajaran::where('pengguna_id', $user->pengguna_id)
            ->where('pembelajaran_id', $id)
            ->first();

        if (!$pendaftaran) {
            return response()->json(['message' => 'Anda belum terdaftar di pelatihan ini'], 400);
        }

        // Validasi keberadaan materi dan kecocokan pembelajaran
        $materi = Materi::with('modul')->find($materi_id);
        if (!$materi || $materi->modul->pembelajaran_id != $id) {
            return response()->json(['message' => 'Materi tidak ditemukan dalam pelatihan ini'], 404);
        }

        $currentModul = $materi->modul;

        // 1. Validasi urutan modul: Semua modul dengan urutan lebih kecil harus sudah selesai materi & kuisnya
        $prevModuls = \App\Models\Modul::where('pembelajaran_id', $id)
            ->where('urutan', '<', $currentModul->urutan)
            ->with(['materi', 'kuis'])
            ->get();

        foreach ($prevModuls as $pm) {
            $pmMateriIds = $pm->materi->pluck('materi_id');
            $pmMateriDone = ProgresMateri::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
                ->whereIn('materi_id', $pmMateriIds)
                ->where('apakah_selesai', true)
                ->count();

            if ($pmMateriIds->count() > 0 && $pmMateriDone < $pmMateriIds->count()) {
                return response()->json([
                    'message' => 'Anda harus menyelesaikan materi pada modul sebelumnya terlebih dahulu.'
                ], 422);
            }

            if ($pm->kuis) {
                $isKuisPassed = \App\Models\RiwayatKuis::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
                    ->where('kuis_id', $pm->kuis->kuis_id)
                    ->where('apakah_lulus', true)
                    ->exists();

                if (!$isKuisPassed) {
                    return response()->json([
                        'message' => 'Anda harus lulus kuis pada modul sebelumnya terlebih dahulu.'
                    ], 422);
                }
            }
        }

        // 2. Validasi urutan materi dalam modul yang sama: Seluruh materi dengan urutan lebih kecil harus selesai
        $prevMateriList = Materi::where('modul_id', $currentModul->modul_id)
            ->where('urutan', '<', $materi->urutan)
            ->pluck('materi_id');

        if ($prevMateriList->count() > 0) {
            $prevMateriDone = ProgresMateri::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
                ->whereIn('materi_id', $prevMateriList)
                ->where('apakah_selesai', true)
                ->count();

            if ($prevMateriDone < $prevMateriList->count()) {
                return response()->json([
                    'message' => 'Anda harus menyelesaikan materi sebelumnya sesuai urutan silabus.'
                ], 422);
            }
        }

        // 3. Validasi Pre-test jika ada pada materi ini
        $preTest = \App\Models\Kuis::where('materi_id', $materi_id)->where('tipe_kuis', 'pre_test')->first();
        if ($preTest) {
            $isPreTestDone = \App\Models\RiwayatKuis::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
                ->where('kuis_id', $preTest->kuis_id)
                ->exists();

            if (!$isPreTestDone) {
                return response()->json([
                    'message' => 'Anda harus mengerjakan Pre-test terlebih dahulu untuk mengakses materi ini.'
                ], 422);
            }
        }

        // Tandai sebagai selesai
        ProgresMateri::updateOrCreate(
            [
                'pendaftaran_id' => $pendaftaran->pendaftaran_id,
                'materi_id' => $materi_id
            ],
            [
                'apakah_selesai' => true,
                'diselesaikan_pada' => now()
            ]
        );

        // Hitung ulang persentase progres
        // Total materi dan kuis dalam pembelajaran ini
        $totalMateri = Materi::whereHas('modul', function($q) use ($id) {
            $q->where('pembelajaran_id', $id);
        })->count();

        $totalKuis = \App\Models\Kuis::whereHas('modul', function($q) use ($id) {
            $q->where('pembelajaran_id', $id);
        })->count();

        $materiSelesai = ProgresMateri::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
            ->where('apakah_selesai', true)
            ->count();

        $kuisLulus = \App\Models\RiwayatKuis::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
            ->where('apakah_lulus', true)
            ->distinct('kuis_id')
            ->count('kuis_id');

        $totalItem = $totalMateri + $totalKuis;
        $itemSelesai = $materiSelesai + $kuisLulus;

        $persentase = $totalItem > 0 ? round(($itemSelesai / $totalItem) * 100) : 0;

        $pendaftaran->persentase_progres = $persentase;

        // Jika progres mencapai 100%, ubah status jika belum menunggu post test
        if ($persentase >= 100 && in_array($pendaftaran->status_pendaftaran, ['terdaftar', 'sedang_berjalan'])) {
            $pendaftaran->status_pendaftaran = 'menunggu_post_test';
        } elseif ($persentase > 0 && $pendaftaran->status_pendaftaran === 'terdaftar') {
            $pendaftaran->status_pendaftaran = 'sedang_berjalan';
        }

        $pendaftaran->save();

        return response()->json([
            'message' => 'Materi berhasil ditandai selesai',
            'data' => [
                'progress' => $persentase,
                'status_pendaftaran' => $pendaftaran->status_pendaftaran
            ]
        ]);
    }

    public function submitUlasan(Request $request, $id)
    {
        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'ulasan' => 'required|string|max:1000'
        ]);

        $user = $request->user();

        $pendaftaran = PendaftaranPembelajaran::where('pengguna_id', $user->pengguna_id)
            ->where('pembelajaran_id', $id)
            ->first();

        if (!$pendaftaran) {
            return response()->json(['message' => 'Anda belum terdaftar di pelatihan ini'], 400);
        }

        // Cek apakah sudah pernah mengirim ulasan
        $existing = UlasanPembelajaran::where('pendaftaran_id', $pendaftaran->pendaftaran_id)->first();
        if ($existing) {
            return response()->json(['message' => 'Anda sudah memberikan ulasan untuk pelatihan ini'], 400);
        }

        UlasanPembelajaran::create([
            'pendaftaran_id' => $pendaftaran->pendaftaran_id,
            'skor_rating' => $request->rating,
            'teks_ulasan' => $request->ulasan
        ]);

        return response()->json(['message' => 'Ulasan berhasil dikirim']);
    }
}
