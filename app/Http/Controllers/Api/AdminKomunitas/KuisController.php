<?php

namespace App\Http\Controllers\Api\AdminKomunitas;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class KuisController extends Controller
{
    private function isPembelajaranAdmin($user, $pembelajaran_id, bool $forWrite = true) {
        $pembelajaran = \App\Models\Pembelajaran::find($pembelajaran_id);
        if (!$pembelajaran) return false;

        return $forWrite
            ? $user->bisaMengelolaPembelajaran($pembelajaran)
            : $user->bisaMengelolaKomunitas($pembelajaran->komunitas_id);
    }

    public function index(Request $request, $modul_id)
    {
        $modul = \App\Models\Modul::findOrFail($modul_id);
        
        if (!$this->isPembelajaranAdmin($request->user(), $modul->pembelajaran_id, false)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $query = \App\Models\Kuis::where('modul_id', $modul_id);

        if ($request->has('materi_id')) {
            $query->where('materi_id', $request->materi_id)->where('tipe_kuis', 'pre_test');
        } elseif ($request->has('tipe_kuis')) {
            $query->where('tipe_kuis', $request->tipe_kuis);
        } else {
            // Default mengambil evaluasi_modul jika tidak ditentukan
            $query->where('tipe_kuis', 'evaluasi_modul');
        }

        $kuis = $query->with('soalKuis')->first();

        return response()->json([
            'message' => 'Kuis Modul berhasil diambil',
            'data' => $kuis
        ]);
    }

    public function store(Request $request, $modul_id)
    {
        $modul = \App\Models\Modul::findOrFail($modul_id);
        
        if (!$this->isPembelajaranAdmin($request->user(), $modul->pembelajaran_id)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $pembelajaran = \App\Models\Pembelajaran::find($modul->pembelajaran_id);
        if ($pembelajaran && $pembelajaran->status === 'dipublikasikan') {
            $pembelajaran->update(['status' => 'draft']);
        }

        $tipeKuis = $request->input('tipe_kuis', 'evaluasi_modul');
        $materiId = $request->input('materi_id');

        $existingKuis = null;
        if ($tipeKuis === 'pre_test') {
            if (!$materiId) {
                return response()->json(['message' => 'materi_id wajib disertakan untuk Pre-test.'], 422);
            }
            $materi = \App\Models\Materi::where('materi_id', $materiId)->where('modul_id', $modul_id)->first();
            if (!$materi) {
                return response()->json(['message' => 'Materi tidak ditemukan dalam modul ini.'], 404);
            }
            $existingKuis = \App\Models\Kuis::where('materi_id', $materiId)->where('tipe_kuis', 'pre_test')->first();
        } else {
            $existingKuis = \App\Models\Kuis::where('modul_id', $modul_id)
                ->where(function ($q) {
                    $q->where('tipe_kuis', 'evaluasi_modul')->orWhereNull('tipe_kuis');
                })->first();
        }

        // Jika kuis evaluasi atau pre-test sudah ada, otomatis perbarui (update) kuis yang sudah ada
        if ($existingKuis) {
            return $this->update($request, $existingKuis->kuis_id);
        }

        $request->validate([
            'judul_kuis' => 'required|string|max:200',
            'tipe_kuis' => 'nullable|in:evaluasi_modul,pre_test',
            'materi_id' => 'nullable|exists:materi,materi_id',
            'durasi_menit' => 'nullable|integer|min:1|max:300',
            'nilai_kelulusan' => $tipeKuis === 'pre_test' ? 'nullable|numeric|min:0|max:100' : 'required|numeric|min:0|max:100',
            'maks_percobaan' => 'nullable|integer|min:1|max:3',
            'acak_soal' => 'nullable|boolean',
            'tampilkan_kunci_setelah' => 'nullable|boolean',
            'grid_config_json' => 'nullable',
            'soal' => 'nullable|array',
            'soal.*.tipe_soal' => 'nullable|in:pilihan_ganda,tts,drag_drop',
            'soal.*.teks_soal' => 'required|string',
            'soal.*.pilihan_jawaban_json' => 'nullable',
            'soal.*.kunci_jawaban' => 'required|string|max:255',
            'soal.*.arah' => 'nullable|in:mendatar,menurun',
            'soal.*.nomor_urut' => 'nullable|integer|min:1',
            'soal.*.baris_mulai' => 'nullable|integer|min:0',
            'soal.*.kolom_mulai' => 'nullable|integer|min:0',
            'soal.*.bobot_nilai' => 'nullable|numeric|min:0',
        ]);

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $gridConfig = $request->grid_config_json;
            if (is_string($gridConfig)) {
                $gridConfig = json_decode($gridConfig, true);
            }

            $kuis = \App\Models\Kuis::create([
                'modul_id' => $modul_id,
                'materi_id' => $tipeKuis === 'pre_test' ? $materiId : null,
                'tipe_kuis' => $tipeKuis,
                'durasi_menit' => $request->input('durasi_menit', 15),
                'judul_kuis' => $request->judul_kuis,
                'nilai_kelulusan' => $request->input('nilai_kelulusan', 0),
                'maks_percobaan' => $request->has('maks_percobaan') ? $request->maks_percobaan : ($tipeKuis === 'pre_test' ? 1 : 3),
                'acak_soal' => $request->has('acak_soal') ? $request->acak_soal : true,
                'tampilkan_kunci_setelah' => $request->has('tampilkan_kunci_setelah') ? $request->tampilkan_kunci_setelah : true,
                'grid_config_json' => $gridConfig,
            ]);

            if ($request->has('soal') && is_array($request->soal)) {
                foreach ($request->soal as $item) {
                    $pilihan = null;
                    if (isset($item['pilihan_jawaban_json'])) {
                        $pilihan = is_string($item['pilihan_jawaban_json']) 
                            ? json_decode($item['pilihan_jawaban_json'], true) 
                            : $item['pilihan_jawaban_json'];
                    }

                    \App\Models\SoalKuis::create([
                        'kuis_id' => $kuis->kuis_id,
                        'tipe_soal' => $item['tipe_soal'] ?? 'pilihan_ganda',
                        'teks_soal' => $item['teks_soal'],
                        'pilihan_jawaban_json' => $pilihan,
                        'kunci_jawaban' => $item['kunci_jawaban'],
                        'arah' => $item['arah'] ?? null,
                        'nomor_urut' => $item['nomor_urut'] ?? null,
                        'baris_mulai' => $item['baris_mulai'] ?? null,
                        'kolom_mulai' => $item['kolom_mulai'] ?? null,
                        'bobot_nilai' => $item['bobot_nilai'] ?? 1,
                    ]);
                }
            }

            \Illuminate\Support\Facades\DB::commit();

            \App\Services\CourseProgressService::syncCourseParticipants($modul->pembelajaran_id);

            return response()->json([
                'message' => 'Kuis dan soal berhasil ditambahkan',
                'data' => $kuis->load('soalKuis')
            ], 201);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Gagal menyimpan kuis: ' . $e->getMessage());
            return response()->json(['message' => 'Gagal menyimpan kuis. Silakan periksa kembali data Anda.'], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $kuis = \App\Models\Kuis::with('soalKuis')->findOrFail($id);
        $modul = \App\Models\Modul::find($kuis->modul_id);

        if (!$this->isPembelajaranAdmin($request->user(), $modul->pembelajaran_id)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        return response()->json([
            'message' => 'Detail Kuis berhasil diambil',
            'data' => $kuis
        ]);
    }

    public function update(Request $request, $id)
    {
        $kuis = \App\Models\Kuis::findOrFail($id);
        $modul = \App\Models\Modul::find($kuis->modul_id);

        if (!$this->isPembelajaranAdmin($request->user(), $modul->pembelajaran_id)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $pembelajaran = \App\Models\Pembelajaran::find($modul->pembelajaran_id);
        if ($pembelajaran && $pembelajaran->status === 'dipublikasikan') {
            $pembelajaran->update(['status' => 'draft']);
        }

        $request->validate([
            'judul_kuis' => 'sometimes|string|max:200',
            'durasi_menit' => 'nullable|integer|min:1|max:300',
            'nilai_kelulusan' => 'sometimes|numeric|min:0|max:100',
            'maks_percobaan' => 'sometimes|integer|min:1|max:3',
            'acak_soal' => 'sometimes|boolean',
            'tampilkan_kunci_setelah' => 'sometimes|boolean',
            'grid_config_json' => 'nullable',
            'soal' => 'nullable|array', // jika dikirim, akan mereplace/sync seluruh soal
            'soal.*.tipe_soal' => 'nullable|in:pilihan_ganda,tts,drag_drop',
            'soal.*.teks_soal' => 'required|string',
            'soal.*.pilihan_jawaban_json' => 'nullable',
            'soal.*.kunci_jawaban' => 'required|string|max:255',
            'soal.*.arah' => 'nullable|in:mendatar,menurun',
            'soal.*.nomor_urut' => 'nullable|integer|min:1',
            'soal.*.baris_mulai' => 'nullable|integer|min:0',
            'soal.*.kolom_mulai' => 'nullable|integer|min:0',
            'soal.*.bobot_nilai' => 'nullable|numeric|min:0',
        ]);

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $updateData = $request->only([
                'judul_kuis', 'durasi_menit', 'nilai_kelulusan', 'maks_percobaan', 'acak_soal', 'tampilkan_kunci_setelah'
            ]);

            if ($request->has('grid_config_json')) {
                $gridConfig = $request->grid_config_json;
                if (is_string($gridConfig)) {
                    $gridConfig = json_decode($gridConfig, true);
                }
                $updateData['grid_config_json'] = $gridConfig;
            }

            $kuis->update($updateData);

            if ($request->has('soal') && is_array($request->soal)) {
                // Untuk kesederhanaan sinkronisasi, hapus semua soal lama dan insert yang baru
                \App\Models\SoalKuis::where('kuis_id', $kuis->kuis_id)->delete();

                foreach ($request->soal as $item) {
                    $pilihan = null;
                    if (isset($item['pilihan_jawaban_json'])) {
                        $pilihan = is_string($item['pilihan_jawaban_json']) 
                            ? json_decode($item['pilihan_jawaban_json'], true) 
                            : $item['pilihan_jawaban_json'];
                    }

                    \App\Models\SoalKuis::create([
                        'kuis_id' => $kuis->kuis_id,
                        'tipe_soal' => $item['tipe_soal'] ?? 'pilihan_ganda',
                        'teks_soal' => $item['teks_soal'],
                        'pilihan_jawaban_json' => $pilihan,
                        'kunci_jawaban' => $item['kunci_jawaban'],
                        'arah' => $item['arah'] ?? null,
                        'nomor_urut' => $item['nomor_urut'] ?? null,
                        'baris_mulai' => $item['baris_mulai'] ?? null,
                        'kolom_mulai' => $item['kolom_mulai'] ?? null,
                        'bobot_nilai' => $item['bobot_nilai'] ?? 1,
                    ]);
                }
            }

            \Illuminate\Support\Facades\DB::commit();

            return response()->json([
                'message' => 'Kuis berhasil diperbarui',
                'data' => $kuis->fresh('soalKuis')
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Gagal memperbarui kuis: ' . $e->getMessage());
            return response()->json(['message' => 'Gagal memperbarui kuis. Silakan periksa kembali data Anda.'], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $kuis = \App\Models\Kuis::findOrFail($id);
        $modul = \App\Models\Modul::find($kuis->modul_id);

        if (!$this->isPembelajaranAdmin($request->user(), $modul->pembelajaran_id)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $pembelajaran = \App\Models\Pembelajaran::find($modul->pembelajaran_id);
        if ($pembelajaran->status === 'dipublikasikan') {
            $pembelajaran->update(['status' => 'draft']);
        }

        $pembelajaranId = $modul->pembelajaran_id;
        $kuis->delete();
        \App\Services\CourseProgressService::syncCourseParticipants($pembelajaranId);

        return response()->json([
            'message' => 'Kuis berhasil dihapus'
        ]);
    }

}

