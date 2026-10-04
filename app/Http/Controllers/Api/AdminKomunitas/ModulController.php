<?php

namespace App\Http\Controllers\Api\AdminKomunitas;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ModulController extends Controller
{
    private function isPembelajaranAdmin($user, $pembelajaran_id, bool $forWrite = true) {
        $pembelajaran = \App\Models\Pembelajaran::find($pembelajaran_id);
        if (!$pembelajaran) return false;

        return $forWrite
            ? $user->bisaMengelolaPembelajaran($pembelajaran)
            : $user->bisaMengelolaKomunitas($pembelajaran->komunitas_id);
    }

    public function index(Request $request, $pembelajaran_id)
    {
        if (!$this->isPembelajaranAdmin($request->user(), $pembelajaran_id, false)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $moduls = \App\Models\Modul::where('pembelajaran_id', $pembelajaran_id)
                    ->orderBy('urutan', 'asc')
                    ->with('materi.preTest.soalKuis', 'kuis.soalKuis', 'kuisBerbobot.soalKuis', 'semuaKuis.soalKuis')
                    ->get();

        return response()->json([
            'message' => 'Daftar Modul berhasil diambil',
            'data' => $moduls
        ]);
    }

    public function store(Request $request, $pembelajaran_id)
    {
        if (!$this->isPembelajaranAdmin($request->user(), $pembelajaran_id)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $pembelajaran = \App\Models\Pembelajaran::find($pembelajaran_id);
        if ($pembelajaran->status === 'dipublikasikan') {
            $pembelajaran->update(['status' => 'draft']);
        }

        $request->validate([
            'judul_modul' => 'required|string|max:255',
            'gambaran_umum' => 'nullable|string',
            'deskripsi' => 'nullable|string',
            'evaluasi_deskripsi' => 'nullable|string',
            'urutan' => [
                'nullable', 'integer', 'min:1',
                \Illuminate\Validation\Rule::unique('modul')->where('pembelajaran_id', $pembelajaran_id)
            ],
            'info_tatap_muka' => 'nullable|string',
            'thumbnail' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'jp_modul' => 'nullable|numeric|min:0|max:99.99',
        ], [
            'thumbnail.max' => 'Ukuran thumbnail tidak boleh lebih dari 2MB.',
            'thumbnail.image' => 'File thumbnail harus berupa gambar.',
        ]);

        $maxUrutan = \App\Models\Modul::where('pembelajaran_id', $pembelajaran_id)->max('urutan') ?? 0;
        $urutan = $request->urutan ?? ($maxUrutan + 1);

        $deskripsiValue = $request->filled('gambaran_umum') 
            ? $request->gambaran_umum 
            : ($request->filled('deskripsi') ? $request->deskripsi : ('Gambaran umum modul ' . $request->judul_modul));

        $thumbnailPath = null;
        if ($request->hasFile('thumbnail')) {
            $thumbnailPath = $request->file('thumbnail')->store('thumbnails/modul', 'public');
        }

        $jpModul = $request->filled('jp_modul') ? (float)$request->jp_modul : 0;

        $modul = \App\Models\Modul::create([
            'pembelajaran_id' => $pembelajaran_id,
            'judul_modul' => $request->judul_modul,
            'gambaran_umum' => $deskripsiValue,
            'evaluasi_deskripsi' => $request->evaluasi_deskripsi ?: 'Evaluasi pemahaman modul',
            'urutan' => $urutan,
            'durasi_total_menit' => 0,
            'jp_modul' => $jpModul,
            'info_tatap_muka' => $request->info_tatap_muka,
            'thumbnail' => $thumbnailPath,
        ]);

        // Rekalkulasi PembelajaranJp jika ada
        $totalDurasi = \App\Models\Modul::where('pembelajaran_id', $pembelajaran_id)->sum('durasi_total_menit');
        $totalJp = \App\Models\Modul::where('pembelajaran_id', $pembelajaran_id)->sum('jp_modul');
        \App\Models\PembelajaranJp::updateOrCreate(
            ['pembelajaran_id' => $pembelajaran_id],
            [
                'durasi_menit' => $totalDurasi,
                'jp_dihitung_sistem' => $totalJp,
            ]
        );

        \App\Services\CourseProgressService::syncCourseParticipants($pembelajaran_id);

        return response()->json([
            'message' => 'Modul berhasil ditambahkan',
            'data' => $modul->load('materi', 'kuis.soalKuis', 'kuisBerbobot.soalKuis', 'semuaKuis.soalKuis')
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $modul = \App\Models\Modul::with('materi', 'kuis.soalKuis', 'kuisBerbobot.soalKuis', 'semuaKuis.soalKuis')->findOrFail($id);

        if (!$this->isPembelajaranAdmin($request->user(), $modul->pembelajaran_id)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        return response()->json([
            'message' => 'Detail Modul berhasil diambil',
            'data' => $modul
        ]);
    }

    public function update(Request $request, $id)
    {
        $modul = \App\Models\Modul::findOrFail($id);

        if (!$this->isPembelajaranAdmin($request->user(), $modul->pembelajaran_id)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $pembelajaran = \App\Models\Pembelajaran::find($modul->pembelajaran_id);
        if ($pembelajaran && $pembelajaran->status === 'dipublikasikan') {
            $pembelajaran->update(['status' => 'draft']);
        }

        $request->validate([
            'judul_modul' => 'sometimes|string|max:255',
            'gambaran_umum' => 'sometimes|string|nullable',
            'deskripsi' => 'sometimes|string|nullable',
            'evaluasi_deskripsi' => 'nullable|string',
            'urutan' => [
                'sometimes', 'integer', 'min:1',
                \Illuminate\Validation\Rule::unique('modul')->where('pembelajaran_id', $modul->pembelajaran_id)->ignore($id, 'modul_id')
            ],
            'info_tatap_muka' => 'nullable|string',
            'thumbnail' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'jp_modul' => 'nullable|numeric|min:0|max:99.99',
        ], [
            'thumbnail.max' => 'Ukuran thumbnail tidak boleh lebih dari 2MB.',
            'thumbnail.image' => 'File thumbnail harus berupa gambar.',
        ]);

        $updateData = $request->only([
            'judul_modul', 'gambaran_umum', 'evaluasi_deskripsi', 'urutan', 'info_tatap_muka'
        ]);

        if ($request->has('deskripsi') && !$request->has('gambaran_umum')) {
            $updateData['gambaran_umum'] = $request->deskripsi;
        }

        if ($request->has('jp_modul')) {
            $updateData['jp_modul'] = (float)$request->jp_modul;
        }

        if ($request->hasFile('thumbnail')) {
            if ($modul->thumbnail && \Illuminate\Support\Facades\Storage::disk('public')->exists($modul->thumbnail)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($modul->thumbnail);
            }
            $updateData['thumbnail'] = $request->file('thumbnail')->store('thumbnails/modul', 'public');
        }

        $modul->update($updateData);

        // Rekalkulasi PembelajaranJp
        $pembelajaranId = $modul->pembelajaran_id;
        $totalDurasi = \App\Models\Modul::where('pembelajaran_id', $pembelajaranId)->sum('durasi_total_menit');
        $totalJp = \App\Models\Modul::where('pembelajaran_id', $pembelajaranId)->sum('jp_modul');
        \App\Models\PembelajaranJp::updateOrCreate(
            ['pembelajaran_id' => $pembelajaranId],
            [
                'durasi_menit' => $totalDurasi,
                'jp_dihitung_sistem' => $totalJp,
            ]
        );

        \App\Services\CourseProgressService::syncCourseParticipants($modul->pembelajaran_id);

        return response()->json([
            'message' => 'Modul berhasil diperbarui',
            'data' => $modul
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $modul = \App\Models\Modul::findOrFail($id);

        if (!$this->isPembelajaranAdmin($request->user(), $modul->pembelajaran_id)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $pembelajaranId = $modul->pembelajaran_id;
        $pembelajaran = \App\Models\Pembelajaran::find($pembelajaranId);
        if ($pembelajaran && $pembelajaran->status === 'dipublikasikan') {
            $pembelajaran->update(['status' => 'draft']);
        }

        if ($modul->thumbnail && \Illuminate\Support\Facades\Storage::disk('public')->exists($modul->thumbnail)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($modul->thumbnail);
        }

        $modul->delete();

        // Rekalkulasi PembelajaranJp jika rekornya ada
        $totalDurasi = \App\Models\Modul::where('pembelajaran_id', $pembelajaranId)->sum('durasi_total_menit');
        $totalJp = \App\Models\Modul::where('pembelajaran_id', $pembelajaranId)->sum('jp_modul');
        \App\Models\PembelajaranJp::where('pembelajaran_id', $pembelajaranId)->update([
            'durasi_menit' => $totalDurasi,
            'jp_dihitung_sistem' => $totalJp,
        ]);

        \App\Services\CourseProgressService::syncCourseParticipants($pembelajaranId);

        return response()->json([
            'message' => 'Modul berhasil dihapus'
        ]);
    }

}
