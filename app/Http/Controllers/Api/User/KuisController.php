<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Kuis;
use App\Models\SoalKuis;
use App\Models\RiwayatKuis;
use App\Models\PendaftaranPembelajaran;
use App\Models\Materi;
use App\Models\Modul;
use App\Models\ProgresMateri;

class KuisController extends Controller
{
    public function show(Request $request, $id, $modul_id, $kuis_id)
    {
        $user = $request->user();

        // Cari pendaftaran
        $pendaftaran = PendaftaranPembelajaran::where('pengguna_id', $user->pengguna_id)
            ->where('pembelajaran_id', $id)
            ->first();

        if (!$pendaftaran) {
            return response()->json(['message' => 'Anda belum terdaftar di pelatihan ini'], 400);
        }

        $kuis = Kuis::with('soalKuis')->where('modul_id', $modul_id)->where('kuis_id', $kuis_id)->first();

        if (!$kuis) {
            return response()->json(['message' => 'Kuis tidak tersedia'], 404);
        }

        // Validasi prasyarat (materi sebelumnya & kuis sebelumnya)
        $prerequisiteError = $this->validatePrerequisites($pendaftaran, $modul_id, $id, $kuis);
        if ($prerequisiteError) {
            return response()->json(['message' => $prerequisiteError], 422);
        }

        // Cek percobaan
        $percobaanKe = RiwayatKuis::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
            ->where('kuis_id', $kuis->kuis_id)
            ->count();

        if ($percobaanKe >= $kuis->maks_percobaan && !RiwayatKuis::where('pendaftaran_id', $pendaftaran->pendaftaran_id)->where('kuis_id', $kuis->kuis_id)->where('apakah_lulus', true)->exists()) {
            return response()->json(['message' => 'Anda telah mencapai batas maksimal percobaan Kuis (' . $kuis->maks_percobaan . ' kali)'], 400);
        }

        // Format soal untuk dikirim
        $soal = $kuis->soalKuis->map(function($s) {
            $pilihan = is_string($s->pilihan_jawaban_json) ? json_decode($s->pilihan_jawaban_json, true) : $s->pilihan_jawaban_json;
            $formatted = [
                'soal_kuis_id' => $s->soal_kuis_id,
                'tipe_soal' => $s->tipe_soal ?? 'pilihan_ganda',
                'teks_soal' => $s->teks_soal,
                'pilihan_jawaban' => $pilihan,
                'arah' => $s->arah,
                'nomor_urut' => $s->nomor_urut,
                'baris_mulai' => $s->baris_mulai,
                'kolom_mulai' => $s->kolom_mulai,
                'panjang_kata' => mb_strlen($s->kunci_jawaban ?? ''),
                'bobot_nilai' => (float) ($s->bobot_nilai ?? 1),
            ];

            if ($s->tipe_soal === 'drag_drop') {
                // Untuk drag_drop, acak seluruh bank opsi dan jangan bocorkan kunci jawaban
                $options = [];
                if (isset($pilihan['all_options']) && is_array($pilihan['all_options'])) {
                    $options = $pilihan['all_options'];
                } elseif (isset($pilihan['blanks']) && is_array($pilihan['blanks'])) {
                    $keys = array_column($pilihan['blanks'], 'kunci');
                    $dist = $pilihan['distractors'] ?? [];
                    $options = array_merge($keys, $dist);
                }
                shuffle($options);

                $blanksCount = isset($pilihan['blanks']) ? count($pilihan['blanks']) : 0;
                $formatted['pilihan_jawaban'] = array_values(array_unique($options));
                $formatted['jumlah_blank'] = $blanksCount;
            } elseif ($s->tipe_soal === 'pilihan_berbobot') {
                // Untuk pilihan_berbobot, kirim mapping opsi tanpa membocorkan skor bobot sebelum selesai
                $optionsMap = [];
                if (is_array($pilihan)) {
                    foreach ($pilihan as $k => $v) {
                        $optionsMap[$k] = is_array($v) ? ($v['teks'] ?? '') : (string)$v;
                    }
                }
                $formatted['pilihan_jawaban'] = $optionsMap;
            }

            return $formatted;
        });

        if ($kuis->acak_soal) {
            $hasTts = $kuis->soalKuis->contains('tipe_soal', 'tts');
            if (!$hasTts) {
                $soal = $soal->shuffle();
            }
        }

        $hasOnlyWeighted = $kuis->soalKuis->count() > 0 && $kuis->soalKuis->every(fn($s) => $s->tipe_soal === 'pilihan_berbobot');
        $effectiveTipeKuis = ($kuis->tipe_kuis === 'kuis_berbobot' || $hasOnlyWeighted) ? 'kuis_berbobot' : $kuis->tipe_kuis;

        return response()->json([
            'message' => 'Soal Kuis berhasil diambil',
            'data' => [
                'kuis_id' => $kuis->kuis_id,
                'tipe_kuis' => $effectiveTipeKuis,
                'is_kuis_berbobot' => ($effectiveTipeKuis === 'kuis_berbobot'),
                'materi_id' => $kuis->materi_id,
                'judul_kuis' => $kuis->judul_kuis,
                'judul_modul' => $kuis->modul->judul_modul,
                'nilai_kelulusan' => $kuis->nilai_kelulusan,
                'durasi_menit' => $kuis->durasi_menit,
                'percobaan_sekarang' => $percobaanKe + 1,
                'maks_percobaan' => $kuis->maks_percobaan,
                'grid_config' => $kuis->grid_config_json,
                'soal' => $soal->values()
            ]
        ]);
    }

    public function submit(Request $request, $id, $modul_id, $kuis_id)
    {
        $request->validate([
            'jawaban' => 'required|array'
        ]);

        $user = $request->user();

        // Cari pendaftaran
        $pendaftaran = PendaftaranPembelajaran::where('pengguna_id', $user->pengguna_id)
            ->where('pembelajaran_id', $id)
            ->first();

        if (!$pendaftaran) {
            return response()->json(['message' => 'Anda belum terdaftar di pelatihan ini'], 400);
        }

        $kuis = Kuis::with('soalKuis')->where('modul_id', $modul_id)->where('kuis_id', $kuis_id)->first();

        if (!$kuis) {
            return response()->json(['message' => 'Kuis tidak ditemukan'], 404);
        }

        // Validasi prasyarat (materi sebelumnya & kuis sebelumnya)
        $prerequisiteError = $this->validatePrerequisites($pendaftaran, $modul_id, $id, $kuis);
        if ($prerequisiteError) {
            return response()->json(['message' => $prerequisiteError], 422);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($request, $pendaftaran, $kuis, $id) {
            $lockedPendaftaran = PendaftaranPembelajaran::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
                ->lockForUpdate()
                ->first();

            $percobaanCount = RiwayatKuis::where('pendaftaran_id', $lockedPendaftaran->pendaftaran_id)
                ->where('kuis_id', $kuis->kuis_id)
                ->lockForUpdate()
                ->count();

            $alreadyPassed = RiwayatKuis::where('pendaftaran_id', $lockedPendaftaran->pendaftaran_id)
                ->where('kuis_id', $kuis->kuis_id)
                ->where('apakah_lulus', true)
                ->exists();

            if ($percobaanCount >= $kuis->maks_percobaan && !$alreadyPassed) {
                return response()->json(['message' => 'Anda telah mencapai batas maksimal percobaan Kuis (' . $kuis->maks_percobaan . ' kali)'], 400);
            }

            // Hitung nilai
            $jawabanUser = $request->jawaban; // array of ['soal_kuis_id' => x, 'jawaban' => 'A' atau 'KATA' atau ['telur', 'insang']]
            $soalList = $kuis->soalKuis->keyBy('soal_kuis_id');

            $totalBobot = 0;
            $skorDidapat = 0;

            foreach ($jawabanUser as $j) {
                $soalId = $j['soal_kuis_id'] ?? null;
                $jawaban = $j['jawaban'] ?? null;

                if ($soalId && isset($soalList[$soalId])) {
                    $s = $soalList[$soalId];
                    $totalBobot += $s->bobot_nilai;

                    if ($s->tipe_soal === 'drag_drop') {
                        // Penilaian proporsional untuk drag_drop
                        $pilihan = is_string($s->pilihan_jawaban_json) ? json_decode($s->pilihan_jawaban_json, true) : $s->pilihan_jawaban_json;
                        $blanks = $pilihan['blanks'] ?? [];
                        $totalBlanks = count($blanks);

                        if ($totalBlanks > 0) {
                            $correctCount = 0;
                            $userAnsList = [];
                            if (is_array($jawaban)) {
                                if (array_is_list($jawaban)) {
                                    $userAnsList = $jawaban;
                                } else {
                                    ksort($jawaban);
                                    $userAnsList = array_values($jawaban);
                                }
                            }

                            foreach ($blanks as $idx => $b) {
                                $kunciBlank = strtoupper(trim((string) ($b['kunci'] ?? '')));
                                $userBlank = isset($userAnsList[$idx]) ? strtoupper(trim((string) $userAnsList[$idx])) : '';

                                if ($kunciBlank !== '' && $kunciBlank === $userBlank) {
                                    $correctCount++;
                                }
                            }

                            // Proporsional sesuai jumlah titik kosong yang dijawab benar
                            $skorDidapat += ($correctCount / $totalBlanks) * $s->bobot_nilai;
                        }
                    } elseif ($s->tipe_soal === 'pilihan_berbobot') {
                        // Penilaian berbobot (tidak ada salah dan benar biner, nilai sesuai bobot opsi yang dipilih)
                        $pilihan = is_string($s->pilihan_jawaban_json) ? json_decode($s->pilihan_jawaban_json, true) : $s->pilihan_jawaban_json;
                        $userChoice = strtoupper(trim((string) $jawaban));
                        
                        $selectedBobot = 0;
                        if (is_array($pilihan) && isset($pilihan[$userChoice])) {
                            $opt = $pilihan[$userChoice];
                            $selectedBobot = is_array($opt) ? (float)($opt['bobot'] ?? 0) : (float)$opt;
                        }

                        // Cari bobot tertinggi opsi sebagai dasar normalisasi
                        $maxBobot = 0;
                        if (is_array($pilihan)) {
                            foreach ($pilihan as $k => $v) {
                                $b = is_array($v) ? (float)($v['bobot'] ?? 0) : (float)$v;
                                if ($b > $maxBobot) $maxBobot = $b;
                            }
                        }

                        $soalBobot = $s->bobot_nilai > 0 ? (float)$s->bobot_nilai : ($maxBobot > 0 ? $maxBobot : 1);
                        if ($maxBobot > 0) {
                            $skorDidapat += ($selectedBobot / $maxBobot) * $soalBobot;
                        }
                    } else {
                        // Pilihan Ganda & TTS: Penilaian biner
                        $kunci = strtoupper(trim((string) $s->kunci_jawaban));
                        $jawabanPeserta = strtoupper(trim((string) $jawaban));

                        if ($kunci !== '' && $kunci === $jawabanPeserta) {
                            $skorDidapat += $s->bobot_nilai;
                        }
                    }
                }
            }

            $isPreTest = ($kuis->tipe_kuis === 'pre_test');
            $hasOnlyWeighted = $kuis->soalKuis->count() > 0 && $kuis->soalKuis->every(fn($s) => $s->tipe_soal === 'pilihan_berbobot');
            $isKuisBerbobot = ($kuis->tipe_kuis === 'kuis_berbobot') || $hasOnlyWeighted;
            $allBobot = $kuis->soalKuis->sum('bobot_nilai');
            $nilaiAkhir = $allBobot > 0 ? round(($skorDidapat / $allBobot) * 100, 2) : 0;
            
            // Penentuan kelulusan
            if ($isPreTest || $isKuisBerbobot) {
                // Pre-test dan Kuis Berbobot tidak ada konsep salah/benar atau tidak lulus
                $apakahLulus = true;
            } else {
                $apakahLulus = ($nilaiAkhir >= $kuis->nilai_kelulusan);
            }

            $percobaanKe = $percobaanCount + 1;

            // Simpan Riwayat
            $riwayat = RiwayatKuis::create([
                'kuis_id' => $kuis->kuis_id,
                'pendaftaran_id' => $lockedPendaftaran->pendaftaran_id,
                'percobaan_ke' => $percobaanKe,
                'nilai' => $nilaiAkhir,
                'apakah_lulus' => $apakahLulus,
                'jawaban_peserta_json' => $jawabanUser,
                'snapshot_soal_json' => $kuis->soalKuis->toArray()
            ]);

            if ($apakahLulus) {
                // Update progress pembelajaran jika kuis selesai
                $this->updateProgress($lockedPendaftaran, $id);
            }

            $successMsg = 'Kuis berhasil disubmit';
            if ($isPreTest) {
                $successMsg = 'Pre-test berhasil diselesaikan';
            } elseif ($isKuisBerbobot) {
                $successMsg = 'Kuis Nilai Berbobot berhasil diselesaikan';
            }

            return response()->json([
                'message' => $successMsg,
                'data' => [
                    'tipe_kuis' => $isKuisBerbobot ? 'kuis_berbobot' : $kuis->tipe_kuis,
                    'is_kuis_berbobot' => $isKuisBerbobot,
                    'nilai' => $nilaiAkhir,
                    'apakah_lulus' => $apakahLulus,
                    'percobaan_ke' => $percobaanKe,
                    'maks_percobaan' => $kuis->maks_percobaan,
                    'sisa_percobaan' => max(0, $kuis->maks_percobaan - $percobaanKe)
                ]
            ]);
        });
    }

    private function updateProgress($pendaftaran, $id)
    {
        $totalMateri = Materi::whereHas('modul', function($q) use ($id) {
            $q->where('pembelajaran_id', $id);
        })->count();

        $totalKuis = Kuis::whereHas('modul', function($q) use ($id) {
            $q->where('pembelajaran_id', $id);
        })->count();

        $materiSelesai = \App\Models\ProgresMateri::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
            ->where('apakah_selesai', true)
            ->count();

        $kuisLulus = RiwayatKuis::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
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
    }

    private function validatePrerequisites($pendaftaran, $modulId, $courseId, $kuis = null)
    {
        $currentModul = Modul::where('pembelajaran_id', $courseId)->find($modulId);
        if (!$currentModul) {
            return 'Modul tidak valid untuk pelatihan ini.';
        }

        // Cek modul sebelumnya
        $prevModuls = Modul::where('pembelajaran_id', $courseId)
            ->where('urutan', '<', $currentModul->urutan)
            ->with(['materi', 'kuis', 'kuisBerbobot'])
            ->get();

        foreach ($prevModuls as $pm) {
            $pmMateriIds = $pm->materi->pluck('materi_id');
            $pmDone = ProgresMateri::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
                ->whereIn('materi_id', $pmMateriIds)
                ->where('apakah_selesai', true)
                ->count();

            if ($pmMateriIds->count() > 0 && $pmDone < $pmMateriIds->count()) {
                return 'Anda harus menyelesaikan materi pada modul sebelumnya terlebih dahulu.';
            }

            if ($pm->kuis) {
                $isPassed = RiwayatKuis::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
                    ->where('kuis_id', $pm->kuis->kuis_id)
                    ->where('apakah_lulus', true)
                    ->exists();

                if (!$isPassed) {
                    return 'Anda harus lulus kuis pada modul sebelumnya terlebih dahulu.';
                }
            }

            if ($pm->kuisBerbobot) {
                $isPassedKb = RiwayatKuis::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
                    ->where('kuis_id', $pm->kuisBerbobot->kuis_id)
                    ->where('apakah_lulus', true)
                    ->exists();

                if (!$isPassedKb) {
                    return 'Anda harus menyelesaikan kuis berbobot pada modul sebelumnya terlebih dahulu.';
                }
            }
        }

        // Jika ini adalah Pre-Test:
        if ($kuis && $kuis->tipe_kuis === 'pre_test') {
            $materi = Materi::find($kuis->materi_id);
            if ($materi) {
                $prevMateriList = Materi::where('modul_id', $modulId)
                    ->where('urutan', '<', $materi->urutan)
                    ->pluck('materi_id');

                if ($prevMateriList->count() > 0) {
                    $prevDone = ProgresMateri::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
                        ->whereIn('materi_id', $prevMateriList)
                        ->where('apakah_selesai', true)
                        ->count();

                    if ($prevDone < $prevMateriList->count()) {
                        return 'Anda harus menyelesaikan materi sebelumnya sesuai urutan silabus.';
                    }
                }
            }
            return null;
        }

        // Cek semua materi di modul saat ini (untuk Evaluasi Modul & Kuis Berbobot)
        $materiIds = Materi::where('modul_id', $modulId)->pluck('materi_id');
        if ($materiIds->count() > 0) {
            $completedCount = ProgresMateri::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
                ->whereIn('materi_id', $materiIds)
                ->where('apakah_selesai', true)
                ->count();

            if ($completedCount < $materiIds->count()) {
                return 'Selesaikan seluruh materi pada modul ini sebelum mengerjakan kuis evaluasi.';
            }
        }

        return null;
    }

    public function showPreTest(Request $request, $id, $materi_id)
    {
        $materi = Materi::findOrFail($materi_id);
        $kuis = Kuis::where('materi_id', $materi_id)->where('tipe_kuis', 'pre_test')->first();
        if (!$kuis) {
            return response()->json(['message' => 'Pre-test tidak ditemukan untuk materi ini.'], 404);
        }
        return $this->show($request, $id, $materi->modul_id, $kuis->kuis_id);
    }

    public function submitPreTest(Request $request, $id, $materi_id)
    {
        $materi = Materi::findOrFail($materi_id);
        $kuis = Kuis::where('materi_id', $materi_id)->where('tipe_kuis', 'pre_test')->first();
        if (!$kuis) {
            return response()->json(['message' => 'Pre-test tidak ditemukan untuk materi ini.'], 404);
        }
        return $this->submit($request, $id, $materi->modul_id, $kuis->kuis_id);
    }
}
