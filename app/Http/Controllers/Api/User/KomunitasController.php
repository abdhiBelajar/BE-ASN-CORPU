<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Komunitas;
use App\Models\Pengguna;

class KomunitasController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Komunitas::withCount(['pembelajaran', 'anggota']);

        // Tampilkan semua komunitas yang ada (tidak difilter lagi)
        $komunitas = $query->get();

        // Ambil daftar komunitas yang di-join oleh user ini
        $joinedKomunitasIds = $user->komunitas()->pluck('komunitas.komunitas_id')->toArray();

        $userRumpun = $user->rumpun_jabatan;
        $totalPenggunaAktif = Pengguna::where('status', 'aktif')->count();

        // Urutkan agar Komunitas Umum tampil pertama
        $sortedKomunitas = $komunitas->sortBy(fn ($k) => $k->isUmum() ? 0 : 1)->values();

        $data = $sortedKomunitas->map(function ($k) use ($joinedKomunitasIds, $userRumpun, $totalPenggunaAktif) {
            $isUmum = $k->isUmum();

            if ($isUmum) {
                $canJoin = true;
                $isJoined = true;
                $members = $totalPenggunaAktif;
            } else {
                // User hanya dapat memilih/join komunitas yang sesuai dengan rumpun jabatannya
                $canJoin = empty($userRumpun) || ($k->rumpun_jabatan === $userRumpun);
                $isJoined = in_array($k->komunitas_id, $joinedKomunitasIds);
                $members = $k->anggota_count;
            }

            return [
                'id' => $k->komunitas_id,
                'title' => $k->nama_komunitas,
                'description' => $k->deskripsi,
                'category' => $k->rumpun_jabatan,
                'courses' => $k->pembelajaran_count,
                'members' => $members,
                'is_joined' => $isJoined,
                'can_join' => $canJoin,
                'is_umum' => $isUmum,
                'thumbnail_url' => $k->thumbnail_url,
                'image' => $k->thumbnail_url,
            ];
        });

        return response()->json([
            'success' => true,
            'user_rumpun_jabatan' => $userRumpun,
            'data' => $data
        ]);
    }

    public function join(Request $request, $id)
    {
        $user = $request->user();
        $komunitas = Komunitas::find($id);

        if (!$komunitas) {
            return response()->json(['message' => 'Komunitas tidak ditemukan'], 404);
        }

        if ($komunitas->status === 'nonaktif') {
            return response()->json(['message' => 'Komunitas sedang tidak aktif'], 400);
        }

        if ($komunitas->isUmum()) {
            return response()->json([
                'success' => true,
                'message' => 'Komunitas Umum terbuka untuk seluruh pegawai; Anda otomatis terdaftar.'
            ], 200);
        }

        // Validasi batasan rumpun jabatan (PRD PST-2, Bab 5)
        if (!empty($user->rumpun_jabatan) && $komunitas->rumpun_jabatan !== $user->rumpun_jabatan) {
            return response()->json([
                'message' => 'Anda tidak memiliki akses untuk bergabung ke komunitas di luar rumpun jabatan Anda (' . $user->rumpun_jabatan . ').'
            ], 403);
        }

        // Cek apakah sudah bergabung
        if ($user->komunitas()->where('komunitas_pengguna.komunitas_id', $id)->exists()) {
            return response()->json(['message' => 'Anda sudah bergabung di komunitas ini'], 400);
        }

        // Gabung komunitas
        $user->komunitas()->attach($id, ['bergabung_pada' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Berhasil bergabung dengan komunitas'
        ]);
    }
}
