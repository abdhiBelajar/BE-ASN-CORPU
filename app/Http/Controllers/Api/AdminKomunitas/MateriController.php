<?php

namespace App\Http\Controllers\Api\AdminKomunitas;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MateriController extends Controller
{
    private function isPembelajaranAdmin($user, $pembelajaran_id, bool $forWrite = true) {
        $pembelajaran = \App\Models\Pembelajaran::find($pembelajaran_id);
        if (!$pembelajaran) return false;

        return $forWrite
            ? $user->bisaMengelolaPembelajaran($pembelajaran)
            : $user->bisaMengelolaKomunitas($pembelajaran->komunitas_id);
    }

    private function rekalkulasiDurasiModul($modul_id) {
        $modul = \App\Models\Modul::find($modul_id);
        if (!$modul) return;

        $totalMenit = \App\Models\Materi::where('modul_id', $modul_id)->sum('durasi_menit');
        // Pertahankan nilai jp_modul jika sudah diisi manual (> 0), jika 0 baru gunakan estimasi default
        $jp = ($modul->jp_modul && (float)$modul->jp_modul > 0)
            ? (float)$modul->jp_modul
            : round(min(3, $totalMenit / 135), 2);

        $modul->update([
            'durasi_total_menit' => $totalMenit,
            'jp_modul' => $jp
        ]);

        // Sinkronisasi PembelajaranJp jika rekornya ada
        $pembelajaranId = $modul->pembelajaran_id;
        $totalDurasiPembelajaran = \App\Models\Modul::where('pembelajaran_id', $pembelajaranId)->sum('durasi_total_menit');
        $totalJpPembelajaran = \App\Models\Modul::where('pembelajaran_id', $pembelajaranId)->sum('jp_modul');

        \App\Models\PembelajaranJp::updateOrCreate(
            ['pembelajaran_id' => $pembelajaranId],
            [
                'durasi_menit' => $totalDurasiPembelajaran,
                'jp_dihitung_sistem' => $totalJpPembelajaran,
            ]
        );
    }

    public function index(Request $request, $modul_id)
    {
        $modul = \App\Models\Modul::findOrFail($modul_id);
        
        if (!$this->isPembelajaranAdmin($request->user(), $modul->pembelajaran_id, false)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $materi = \App\Models\Materi::where('modul_id', $modul_id)
                    ->orderBy('urutan', 'asc')
                    ->get();

        return response()->json([
            'message' => 'Daftar Materi berhasil diambil',
            'data' => $materi
        ]);
    }

    public function store(Request $request, $modul_id)
    {
        $modul = \App\Models\Modul::findOrFail($modul_id);
        
        if (!$this->isPembelajaranAdmin($request->user(), $modul->pembelajaran_id)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $pembelajaran = \App\Models\Pembelajaran::find($modul->pembelajaran_id);
        if ($pembelajaran && ($pembelajaran->status === 'dipublikasikan' || $pembelajaran->dipublikasikan_pada !== null)) {
            $pembelajaran->update(['status' => 'menunggu_approval']);
            \App\Models\ValidasiPembelajaran::updateOrCreate(
                ['pembelajaran_id' => $pembelajaran->pembelajaran_id],
                [
                    'divalidasi_oleh_pengguna_id' => $request->user()->pengguna_id,
                    'status_validasi' => 'diajukan',
                    'catatan' => 'Materi baru ditambahkan oleh Admin Komunitas. Menunggu validasi ulang Admin BKPSDM.'
                ]
            );
        }

        $request->validate([
            'judul_materi' => 'required|string|max:255',
            'tipe_materi' => 'required|in:pdf,video_embed,h5p,scorm',
            'tautan_atau_berkas_embed' => 'required_if:tipe_materi,video_embed,h5p|nullable|string|max:1000',
            'file_pdf' => 'required_if:tipe_materi,pdf|nullable|file|mimes:pdf|max:10240',
            'file_scorm' => 'nullable|file|mimes:zip|max:102400',
            'durasi_menit' => 'nullable|integer|min:1',
            'apakah_wajib' => 'nullable',
            'urutan' => [
                'nullable', 'integer', 'min:1',
                \Illuminate\Validation\Rule::unique('materi')->where('modul_id', $modul_id)
            ],
        ]);

        $url = '';
        if ($request->tipe_materi === 'pdf') {
            if (!$request->hasFile('file_pdf')) {
                return response()->json(['message' => 'File PDF wajib diunggah.'], 422);
            }
            $path = $request->file('file_pdf')->store('materi_pdf', 'public');
            $url = '/storage/' . $path;
        } elseif ($request->tipe_materi === 'scorm') {
            if ($request->hasFile('file_scorm')) {
                try {
                    $scormResult = (new \App\Services\ScormService())->extractAndGetEntryUrl($request->file('file_scorm'));
                    $url = $scormResult['url'];
                } catch (\Throwable $e) {
                    return response()->json(['message' => 'Gagal memproses paket SCORM: ' . $e->getMessage()], 422);
                }
            } elseif ($request->filled('tautan_atau_berkas_embed')) {
                $raw = trim($request->tautan_atau_berkas_embed ?: '');
                if (preg_match('/<iframe\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $raw, $matches)) {
                    $raw = $matches[1];
                }
                $url = $raw;
            } else {
                return response()->json(['message' => 'Silakan unggah berkas ZIP paket SCORM atau masukkan tautan eksternal SCORM.'], 422);
            }
        } else {
            $raw = trim($request->tautan_atau_berkas_embed ?: '');
            // Jika user memasukkan iframe tag lengkap, ekstrak URL src-nya
            if (preg_match('/<iframe\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $raw, $matches)) {
                $raw = $matches[1];
            }
            $url = $raw;
        }

        $maxUrutan = \App\Models\Materi::where('modul_id', $modul_id)->max('urutan') ?? 0;
        $urutan = $request->urutan ?? ($maxUrutan + 1);
        $durasiMenit = $request->durasi_menit ?? 15;

        $materi = \App\Models\Materi::create([
            'modul_id' => $modul_id,
            'judul_materi' => $request->judul_materi,
            'tipe_materi' => $request->tipe_materi,
            'tautan_atau_berkas' => $url,
            'durasi_menit' => $durasiMenit,
            'apakah_wajib' => $request->has('apakah_wajib') ? filter_var($request->apakah_wajib, FILTER_VALIDATE_BOOLEAN) : true,
            'urutan' => $urutan,
        ]);

        $this->rekalkulasiDurasiModul($modul_id);
        \App\Services\CourseProgressService::syncCourseParticipants($modul->pembelajaran_id);

        return response()->json([
            'message' => 'Materi berhasil ditambahkan',
            'data' => $materi,
            'status_pembelajaran' => $pembelajaran ? $pembelajaran->status : null
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $materi = \App\Models\Materi::findOrFail($id);
        $modul = \App\Models\Modul::find($materi->modul_id);

        if (!$this->isPembelajaranAdmin($request->user(), $modul->pembelajaran_id)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        return response()->json([
            'message' => 'Detail Materi berhasil diambil',
            'data' => $materi
        ]);
    }

    public function update(Request $request, $id)
    {
        $materi = \App\Models\Materi::findOrFail($id);
        $modul = \App\Models\Modul::find($materi->modul_id);

        if (!$this->isPembelajaranAdmin($request->user(), $modul->pembelajaran_id)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $pembelajaran = \App\Models\Pembelajaran::find($modul->pembelajaran_id);
        if ($pembelajaran && ($pembelajaran->status === 'dipublikasikan' || $pembelajaran->dipublikasikan_pada !== null)) {
            $pembelajaran->update(['status' => 'menunggu_approval']);
            \App\Models\ValidasiPembelajaran::updateOrCreate(
                ['pembelajaran_id' => $pembelajaran->pembelajaran_id],
                [
                    'divalidasi_oleh_pengguna_id' => $request->user()->pengguna_id,
                    'status_validasi' => 'diajukan',
                    'catatan' => 'Materi pada kursus diperbarui oleh Admin Komunitas. Menunggu validasi ulang Admin BKPSDM.'
                ]
            );
        }

        $request->validate([
            'judul_materi' => 'sometimes|string|max:255',
            'tipe_materi' => 'sometimes|in:pdf,video_embed,h5p,scorm',
            'durasi_menit' => 'sometimes|integer|min:1',
            'apakah_wajib' => 'nullable|in:0,1,true,false',
            'urutan' => [
                'sometimes', 'integer', 'min:1',
                \Illuminate\Validation\Rule::unique('materi')->where('modul_id', $modul->modul_id)->ignore($id, 'materi_id')
            ],
            'tautan_atau_berkas_embed' => 'nullable|string|max:1000',
            'file_pdf' => 'nullable|file|mimes:pdf|max:10240',
            'file_scorm' => 'nullable|file|mimes:zip|max:102400',
        ]);

        $newTipe = $request->input('tipe_materi', $materi->tipe_materi);
        $updateData = $request->only(['judul_materi', 'durasi_menit', 'urutan', 'tipe_materi']);
        if ($request->has('apakah_wajib')) {
            $updateData['apakah_wajib'] = filter_var($request->apakah_wajib, FILTER_VALIDATE_BOOLEAN);
        }

        $scormService = new \App\Services\ScormService();

        if ($request->hasFile('file_pdf')) {
            if ($materi->tipe_materi === 'pdf' && $materi->tautan_atau_berkas) {
                $oldPath = str_replace('/storage/', '', $materi->tautan_atau_berkas);
                \Illuminate\Support\Facades\Storage::disk('public')->delete($oldPath);
            }
            if ($materi->tipe_materi === 'scorm') {
                $scormService->deleteScormByUrl($materi->tautan_atau_berkas);
            }
            $path = $request->file('file_pdf')->store('materi_pdf', 'public');
            $updateData['tautan_atau_berkas'] = '/storage/' . $path;
            $updateData['tipe_materi'] = 'pdf';
        } elseif ($request->hasFile('file_scorm')) {
            if ($materi->tipe_materi === 'pdf' && $materi->tautan_atau_berkas) {
                $oldPath = str_replace('/storage/', '', $materi->tautan_atau_berkas);
                \Illuminate\Support\Facades\Storage::disk('public')->delete($oldPath);
            }
            if ($materi->tipe_materi === 'scorm') {
                $scormService->deleteScormByUrl($materi->tautan_atau_berkas);
            }
            try {
                $scormResult = $scormService->extractAndGetEntryUrl($request->file('file_scorm'));
                $updateData['tautan_atau_berkas'] = $scormResult['url'];
                $updateData['tipe_materi'] = 'scorm';
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Gagal memproses paket SCORM: ' . $e->getMessage()], 422);
            }
        } elseif ($request->has('tautan_atau_berkas_embed') && in_array($newTipe, ['video_embed', 'h5p', 'scorm'])) {
            $raw = trim($request->tautan_atau_berkas_embed ?: '');
            if (preg_match('/<iframe\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $raw, $matches)) {
                $raw = $matches[1];
            }
            if ($materi->tipe_materi === 'scorm' && $materi->tautan_atau_berkas !== $raw) {
                $scormService->deleteScormByUrl($materi->tautan_atau_berkas);
            }
            $updateData['tautan_atau_berkas'] = $raw;
        }

        $materi->update($updateData);

        $this->rekalkulasiDurasiModul($modul->modul_id);
        \App\Services\CourseProgressService::syncCourseParticipants($modul->pembelajaran_id);

        return response()->json([
            'message' => 'Materi berhasil diperbarui',
            'data' => $materi,
            'status_pembelajaran' => $pembelajaran ? $pembelajaran->status : null
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $materi = \App\Models\Materi::findOrFail($id);
        $modul = \App\Models\Modul::find($materi->modul_id);

        if (!$this->isPembelajaranAdmin($request->user(), $modul->pembelajaran_id)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $pembelajaran = \App\Models\Pembelajaran::find($modul->pembelajaran_id);
        if ($pembelajaran && ($pembelajaran->status === 'dipublikasikan' || $pembelajaran->dipublikasikan_pada !== null)) {
            $pembelajaran->update(['status' => 'menunggu_approval']);
            \App\Models\ValidasiPembelajaran::updateOrCreate(
                ['pembelajaran_id' => $pembelajaran->pembelajaran_id],
                [
                    'divalidasi_oleh_pengguna_id' => $request->user()->pengguna_id,
                    'status_validasi' => 'diajukan',
                    'catatan' => 'Materi pada kursus dihapus oleh Admin Komunitas. Menunggu validasi ulang Admin BKPSDM.'
                ]
            );
        }

        // Hapus berkas fisik jika bertipe PDF
        if ($materi->tipe_materi === 'pdf' && $materi->tautan_atau_berkas) {
            $filePath = str_replace('/storage/', '', $materi->tautan_atau_berkas);
            \Illuminate\Support\Facades\Storage::disk('public')->delete($filePath);
        }

        // Hapus folder SCORM jika bertipe SCORM lokal
        if ($materi->tipe_materi === 'scorm' && $materi->tautan_atau_berkas) {
            (new \App\Services\ScormService())->deleteScormByUrl($materi->tautan_atau_berkas);
        }

        $pembelajaranId = $modul->pembelajaran_id;
        $materi->delete();
        
        $this->rekalkulasiDurasiModul($modul->modul_id);
        \App\Services\CourseProgressService::syncCourseParticipants($pembelajaranId);

        return response()->json([
            'message' => 'Materi berhasil dihapus',
            'status_pembelajaran' => $pembelajaran ? $pembelajaran->status : null
        ]);
    }

}
