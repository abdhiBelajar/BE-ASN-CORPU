<?php

namespace App\Http\Controllers\Api\AdminBkpsdm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class KomunitasController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = \App\Models\Komunitas::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nama_komunitas', 'like', "%{$search}%")
                  ->orWhere('deskripsi', 'like', "%{$search}%");
            });
        }

        if ($request->filled('rumpun_jabatan')) {
            $query->where('rumpun_jabatan', $request->rumpun_jabatan);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('page') || $request->has('per_page')) {
            $perPage = $request->input('per_page', 10);
            $komunitas = $query->latest('komunitas_id')->paginate($perPage);

            return response()->json([
                'message' => 'Daftar Komunitas',
                'data' => $komunitas->items(),
                'meta' => [
                    'current_page' => $komunitas->currentPage(),
                    'last_page' => $komunitas->lastPage(),
                    'per_page' => $komunitas->perPage(),
                    'total' => $komunitas->total(),
                ]
            ]);
        }

        return response()->json([
            'message' => 'Daftar Komunitas',
            'data' => $query->latest('komunitas_id')->get()
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'nama_komunitas' => 'required|string|max:255|unique:komunitas',
            'deskripsi' => 'nullable|string',
            'rumpun_jabatan' => 'required|in:JPT,JA,JF,JP,Pelaksana,UMUM',
            'thumbnail' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ], [
            'thumbnail.max' => 'Ukuran thumbnail tidak boleh lebih dari 2MB.',
            'thumbnail.image' => 'File thumbnail harus berupa gambar.',
        ]);

        $rumpunJabatan = $request->rumpun_jabatan === 'Pelaksana' ? 'JP' : $request->rumpun_jabatan;

        $thumbnailPath = null;
        if ($request->hasFile('thumbnail')) {
            $thumbnailPath = $request->file('thumbnail')->store('thumbnails/komunitas', 'public');
        }

        $komunitas = \App\Models\Komunitas::create([
            'nama_komunitas' => $request->nama_komunitas,
            'deskripsi' => $request->deskripsi,
            'rumpun_jabatan' => $rumpunJabatan,
            'thumbnail' => $thumbnailPath,
            'dibuat_oleh_pengguna_id' => $request->user()->pengguna_id,
            'status' => 'aktif'
        ]);

        return response()->json([
            'message' => 'Komunitas berhasil dibuat',
            'data' => $komunitas
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $komunitas = \App\Models\Komunitas::findOrFail($id);
        return response()->json([
            'message' => 'Detail Komunitas',
            'data' => $komunitas
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $komunitas = \App\Models\Komunitas::findOrFail($id);

        $request->validate([
            'nama_komunitas' => 'nullable|string|max:255|unique:komunitas,nama_komunitas,' . $id . ',komunitas_id',
            'deskripsi' => 'nullable|string',
            'rumpun_jabatan' => 'nullable|in:JPT,JA,JF,JP,Pelaksana,UMUM',
            'status' => 'nullable|in:aktif,nonaktif',
            'thumbnail' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        ], [
            'thumbnail.max' => 'Ukuran thumbnail tidak boleh lebih dari 2MB.',
            'thumbnail.image' => 'File thumbnail harus berupa gambar.',
        ]);

        $data = $request->only([
            'nama_komunitas', 'deskripsi', 'rumpun_jabatan', 'status'
        ]);

        if (isset($data['rumpun_jabatan']) && $data['rumpun_jabatan'] === 'Pelaksana') {
            $data['rumpun_jabatan'] = 'JP';
        }

        if ($komunitas->nama_komunitas === 'Komunitas Umum') {
            if (isset($data['rumpun_jabatan']) && $data['rumpun_jabatan'] !== 'UMUM') {
                return response()->json([
                    'message' => 'Rumpun jabatan Komunitas Umum utama tidak dapat diubah.'
                ], 422);
            }
            if (isset($data['status']) && $data['status'] === 'nonaktif') {
                return response()->json([
                    'message' => 'Komunitas Umum utama tidak dapat dinonaktifkan.'
                ], 422);
            }
        }

        if ($request->hasFile('thumbnail')) {
            if ($komunitas->thumbnail && \Illuminate\Support\Facades\Storage::disk('public')->exists($komunitas->thumbnail)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($komunitas->thumbnail);
            }
            $data['thumbnail'] = $request->file('thumbnail')->store('thumbnails/komunitas', 'public');
        }

        $komunitas->update($data);

        return response()->json([
            'message' => 'Komunitas berhasil diupdate',
            'data' => $komunitas
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $komunitas = \App\Models\Komunitas::findOrFail($id);
        
        if ($komunitas->nama_komunitas === 'Komunitas Umum') {
            return response()->json([
                'message' => 'Komunitas Umum utama tidak dapat dihapus.'
            ], 422);
        }

        if ($komunitas->thumbnail && \Illuminate\Support\Facades\Storage::disk('public')->exists($komunitas->thumbnail)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($komunitas->thumbnail);
        }

        $komunitas->delete();

        return response()->json([
            'message' => 'Komunitas berhasil dihapus'
        ]);
    }
}
