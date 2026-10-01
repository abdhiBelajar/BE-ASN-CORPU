<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Komunitas;
use App\Models\Pembelajaran;
use App\Models\PendaftaranPembelajaran;

class KatalogController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        // Ambil daftar komunitas yang telah diikuti user
        $joinedKomunitas = $user->komunitas()
            ->select('komunitas.komunitas_id', 'komunitas.nama_komunitas', 'komunitas.rumpun_jabatan')
            ->get();

        // Tambahkan Komunitas Umum jika aktif
        $komunitasUmum = Komunitas::query()->umum()->where('status', 'aktif')
            ->select('komunitas.komunitas_id', 'komunitas.nama_komunitas', 'komunitas.rumpun_jabatan')
            ->first();

        if ($komunitasUmum && !$joinedKomunitas->contains('komunitas_id', $komunitasUmum->komunitas_id)) {
            $joinedKomunitas->push($komunitasUmum);
        }

        $joinedKomunitasIds = $joinedKomunitas->pluck('komunitas_id')->map(fn ($v) => (int) $v)->toArray();

        // Jika user belum bergabung ke komunitas mana pun, katalog tidak menampilkan pembelajaran
        if (empty($joinedKomunitasIds)) {
            return response()->json([
                'message' => 'Anda belum bergabung dengan komunitas manapun. Silakan bergabung dengan komunitas terlebih dahulu.',
                'has_joined_community' => false,
                'joined_communities' => [],
                'data' => [
                    'current_page' => 1,
                    'data' => [],
                    'total' => 0,
                    'last_page' => 1
                ]
            ]);
        }

        $query = Pembelajaran::where(function($q) {
                $q->where('status', 'dipublikasikan')
                  ->orWhere('status', 'menunggu_approval')
                  ->orWhere('status', 'ditolak')
                  ->orWhereNotNull('dipublikasikan_pada');
            })
            ->whereIn('komunitas_id', $joinedKomunitasIds)
            ->withCount('modul')
            ->with(['pembelajaranJp', 'komunitas:komunitas_id,nama_komunitas,rumpun_jabatan', 'kategoriKursus']);

        // Filter komunitas spesifik (jika dipilih)
        if ($request->filled('komunitas_id') && $request->komunitas_id !== 'all') {
            $query->where('komunitas_id', $request->komunitas_id);
        }

        if ($request->has('kategori') && !in_array($request->kategori, ['Semua Kategori', 'Semua', 'all', ''])) {
            $cat = $request->kategori;
            $query->where(function($q) use ($cat) {
                $q->where('kategori', $cat);
                if (is_numeric($cat)) {
                    $q->orWhere('kategori_id', $cat);
                } else {
                    $q->orWhereHas('kategoriKursus', function($kq) use ($cat) {
                        $kq->where('nama_kategori', $cat);
                    });
                }
            });
        }

        if ($request->filled('kategori_id') && $request->kategori_id !== 'all') {
            $query->where('kategori_id', $request->kategori_id);
        }

        if ($request->has('search') && !empty($request->search)) {
            $query->where(function($q) use ($request) {
                $q->where('judul_pembelajaran', 'like', '%' . $request->search . '%')
                  ->orWhere('deskripsi', 'like', '%' . $request->search . '%');
            });
        }

        $sort = $request->input('sort', 'terbaru');
        if ($sort === 'terbaru') {
            $query->latest('dipublikasikan_pada');
        } else {
            $query->orderBy('judul_pembelajaran', 'asc');
        }

        $pembelajaran = $query->paginate(12);

        // Map to add enrolled status
        $enrolledIds = PendaftaranPembelajaran::where('pengguna_id', $user->pengguna_id)->pluck('pembelajaran_id')->toArray();

        $items = $pembelajaran->getCollection()->map(function ($item) use ($enrolledIds) {
            $jp = $item->pembelajaranJp->first();
            $isLockedReview = $item->status !== 'dipublikasikan';
            $lockReason = null;
            if ($isLockedReview) {
                if ($item->status === 'ditolak') {
                    $lockReason = 'Kursus ini sedang dalam revisi konten dan menunggu peninjauan ulang oleh Admin BKPSDM';
                } elseif ($item->status === 'menunggu_approval') {
                    $lockReason = 'Materi pembelajaran sedang dalam pembaruan dan menunggu approval Admin BKPSDM';
                } else {
                    $lockReason = 'Kursus ini sedang dalam pembaruan konten dan menunggu approval Admin BKPSDM';
                }
            }
            return [
                'id' => $item->pembelajaran_id,
                'komunitas_id' => $item->komunitas_id,
                'nama_komunitas' => $item->komunitas->nama_komunitas ?? '-',
                'image' => $item->thumbnail_url,
                'thumbnail_url' => $item->thumbnail_url,
                'kategori_id' => $item->kategori_id,
                'category' => $item->kategoriKursus?->nama_kategori ?? $item->kategori ?? 'Lainnya',
                'title' => $item->judul_pembelajaran,
                'description' => $item->deskripsi,
                'jpl' => $jp ? ($jp->jp_final ?? $jp->jp_dihitung_sistem ?? 0) : 0,
                'modules' => $item->modul_count,
                'isEnrolled' => in_array($item->pembelajaran_id, $enrolledIds),
                'status' => $item->status,
                'is_locked_review' => $isLockedReview,
                'lock_reason' => $lockReason,
            ];
        });

        $pembelajaran->setCollection($items);

        return response()->json([
            'message' => 'Katalog berhasil diambil',
            'has_joined_community' => true,
            'joined_communities' => $joinedKomunitas,
            'data' => $pembelajaran
        ]);
    }

    public function enroll(Request $request, $id)
    {
        $user = $request->user();
        
        $pembelajaran = Pembelajaran::with('komunitas')->find($id);
        if (!$pembelajaran) {
            return response()->json(['message' => 'Pembelajaran tidak ditemukan'], 404);
        }

        if ($pembelajaran->status !== 'dipublikasikan') {
            return response()->json([
                'message' => 'Pembelajaran ini sedang dalam peninjauan materi oleh Admin BKPSDM. Pendaftaran sementara terkunci.'
            ], 400);
        }

        $komunitas = $pembelajaran->komunitas;
        $isUmum = $komunitas && $komunitas->isUmum();

        // Validasi: Peserta HARUS sudah bergabung ke komunitas penyelenggara (kecuali Komunitas Umum)
        if (!$isUmum) {
            $isMember = $user->komunitas()->where('komunitas_pengguna.komunitas_id', $pembelajaran->komunitas_id)->exists();
            if (!$isMember) {
                $namaKomunitas = $komunitas->nama_komunitas ?? 'komunitas terkait';
                return response()->json([
                    'message' => 'Anda harus bergabung dengan "' . $namaKomunitas . '" terlebih dahulu sebelum dapat mendaftar pelatihan ini.'
                ], 403);
            }

            // Validasi batas rumpun jabatan (PRD PST-2, Bab 5)
            if (!empty($user->rumpun_jabatan) && $komunitas && $komunitas->rumpun_jabatan !== $user->rumpun_jabatan) {
                return response()->json([
                    'message' => 'Anda tidak memiliki akses untuk mendaftar pembelajaran di luar rumpun jabatan Anda (' . $user->rumpun_jabatan . ').'
                ], 403);
            }
        }

        $exists = PendaftaranPembelajaran::where('pengguna_id', $user->pengguna_id)
            ->where('pembelajaran_id', $id)
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Anda sudah terdaftar di pelatihan ini'], 400);
        }

        PendaftaranPembelajaran::create([
            'pengguna_id' => $user->pengguna_id,
            'pembelajaran_id' => $id,
            'status_pendaftaran' => 'terdaftar',
            'persentase_progres' => 0,
            'terdaftar_pada' => now()
        ]);

        return response()->json([
            'message' => 'Berhasil mendaftar ke pelatihan'
        ]);
    }
}
