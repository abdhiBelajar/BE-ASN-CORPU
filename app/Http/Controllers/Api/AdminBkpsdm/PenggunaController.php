<?php

namespace App\Http\Controllers\Api\AdminBkpsdm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PenggunaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = \App\Models\Pengguna::with(['daftarPeran']);

        if ($request->has('peran') && !empty($request->peran)) {
            $reqPeran = $request->peran;
            $query->where(function($q) use ($reqPeran) {
                $q->where('peran', $reqPeran)
                  ->orWhereHas('daftarPeran', function($subQ) use ($reqPeran) {
                      $subQ->where('peran', $reqPeran);
                  });
            });
        }

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('nama_lengkap', 'like', '%' . $search . '%')
                  ->orWhere('nip', 'like', '%' . $search . '%')
                  ->orWhere('email', 'like', '%' . $search . '%');
            });
        }

        $transformUser = function($u) {
            $data = $u->toArray();
            $data['roles'] = $u->roles_list;
            $ak = \App\Models\AdminKomunitas::where('pengguna_id', $u->pengguna_id)->first();
            $data['komunitas_id'] = $ak?->komunitas_id;
            return $data;
        };

        if ($request->has('page') || $request->has('per_page')) {
            $perPage = (int) $request->input('per_page', 25);
            $pengguna = $query->latest('dibuat_pada')->paginate($perPage);

            return response()->json([
                'message' => 'Daftar Pengguna',
                'data' => array_map($transformUser, $pengguna->items()),
                'meta' => [
                    'current_page' => $pengguna->currentPage(),
                    'last_page' => $pengguna->lastPage(),
                    'per_page' => $pengguna->perPage(),
                    'total' => $pengguna->total(),
                ]
            ]);
        }

        $pengguna = $query->latest('dibuat_pada')->get();

        return response()->json([
            'message' => 'Daftar Pengguna',
            'data' => $pengguna->map($transformUser)
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'nip' => 'required|string|unique:pengguna',
            'nama_lengkap' => 'required|string',
            'email' => 'nullable|email|unique:pengguna',
            'roles' => 'nullable|array|min:1',
            'roles.*' => 'in:admin_bkpsdm,admin_komunitas,peserta',
            'peran' => 'nullable|in:admin_bkpsdm,admin_komunitas,peserta',
            'jabatan' => 'nullable|string',
            'rumpun_jabatan' => 'nullable|in:JPT,JA,JF,JP,Pelaksana',
            'unit_kerja' => 'nullable|string',
            'komunitas_id' => 'nullable|exists:komunitas,komunitas_id'
        ]);

        return \Illuminate\Support\Facades\DB::transaction(function() use ($request) {
            $passwordDefault = substr($request->nip, -8);
            $rumpun = $request->rumpun_jabatan === 'Pelaksana' ? 'JP' : $request->rumpun_jabatan;

            $roles = $request->roles;
            if (!$roles && $request->peran) {
                $roles = [$request->peran];
            }
            if (!$roles) {
                $roles = ['peserta'];
            }

            $primaryRole = $roles[0] ?? 'peserta';

            $pengguna = \App\Models\Pengguna::create([
                'nip' => $request->nip,
                'nama_lengkap' => $request->nama_lengkap,
                'email' => $request->email,
                'kata_sandi_hash' => \Illuminate\Support\Facades\Hash::make($passwordDefault),
                'peran' => $primaryRole,
                'jabatan' => $request->jabatan,
                'rumpun_jabatan' => $rumpun,
                'unit_kerja' => $request->unit_kerja,
                'status' => 'aktif'
            ]);

            // Sinkronkan peran ke tabel pengguna_peran
            foreach ($roles as $role) {
                \App\Models\PenggunaPeran::create([
                    'pengguna_id' => $pengguna->pengguna_id,
                    'peran' => $role,
                    'komunitas_id' => ($role === 'admin_komunitas') ? $request->komunitas_id : null,
                ]);
            }

            if (in_array('admin_komunitas', $roles, true) && $request->has('komunitas_id') && $request->komunitas_id) {
                \App\Models\AdminKomunitas::create([
                    'pengguna_id' => $pengguna->pengguna_id,
                    'komunitas_id' => $request->komunitas_id
                ]);
            }

            $userData = $pengguna->toArray();
            $userData['roles'] = $pengguna->roles_list;
            $userData['komunitas_id'] = $request->komunitas_id;

            return response()->json([
                'message' => 'Pengguna berhasil ditambahkan',
                'data' => $userData
            ], 201);
        });
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $pengguna = \App\Models\Pengguna::with('daftarPeran')->findOrFail($id);
        $userData = $pengguna->toArray();
        $userData['roles'] = $pengguna->roles_list;
        $ak = \App\Models\AdminKomunitas::where('pengguna_id', $pengguna->pengguna_id)->first();
        $userData['komunitas_id'] = $ak?->komunitas_id;

        return response()->json([
            'message' => 'Detail Pengguna',
            'data' => $userData
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $request->validate([
            'roles' => 'nullable|array|min:1',
            'roles.*' => 'in:admin_bkpsdm,admin_komunitas,peserta',
            'peran' => 'nullable|in:admin_bkpsdm,admin_komunitas,peserta',
            'status' => 'nullable|in:aktif,nonaktif',
            'komunitas_id' => 'nullable|exists:komunitas,komunitas_id'
        ]);

        $pengguna = \App\Models\Pengguna::findOrFail($id);
        
        \Illuminate\Support\Facades\DB::transaction(function() use ($request, $pengguna) {
            $roles = $request->roles;
            if (!$roles && $request->has('peran') && $request->peran) {
                $roles = [$request->peran];
            }

            if ($roles) {
                // Perbarui tabel pivot pengguna_peran
                \App\Models\PenggunaPeran::where('pengguna_id', $pengguna->pengguna_id)->delete();
                foreach ($roles as $role) {
                    \App\Models\PenggunaPeran::create([
                        'pengguna_id' => $pengguna->pengguna_id,
                        'peran' => $role,
                        'komunitas_id' => ($role === 'admin_komunitas') ? $request->komunitas_id : null,
                    ]);
                }

                $pengguna->peran = $roles[0];

                if (in_array('admin_komunitas', $roles, true)) {
                    if ($request->has('komunitas_id') && $request->komunitas_id) {
                        \App\Models\AdminKomunitas::updateOrCreate(
                            ['pengguna_id' => $pengguna->pengguna_id],
                            ['komunitas_id' => $request->komunitas_id]
                        );
                    }
                } else {
                    \App\Models\AdminKomunitas::where('pengguna_id', $pengguna->pengguna_id)->delete();
                }
            }
            
            if ($request->has('status')) {
                $pengguna->status = $request->status;
            }
            
            $pengguna->save();
        });

        $userData = $pengguna->fresh()->toArray();
        $userData['roles'] = $pengguna->fresh()->roles_list;
        $ak = \App\Models\AdminKomunitas::where('pengguna_id', $pengguna->pengguna_id)->first();
        $userData['komunitas_id'] = $ak?->komunitas_id;

        return response()->json([
            'message' => 'Data pengguna berhasil diubah',
            'data' => $userData
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $pengguna = \App\Models\Pengguna::findOrFail($id);

        // 1. Proteksi Anti-Lockout: Cegah menghapus akun sendiri
        if ((string)$pengguna->pengguna_id === (string)$request->user()->pengguna_id) {
            return response()->json([
                'message' => 'Anda tidak dapat menghapus akun Anda sendiri.'
            ], 400);
        }

        // 2. Proteksi Anti-Lockout: Cegah menghapus admin_bkpsdm terakhir
        if ($pengguna->peran === 'admin_bkpsdm') {
            $adminCount = \App\Models\Pengguna::where('peran', 'admin_bkpsdm')->count();
            if ($adminCount <= 1) {
                return response()->json([
                    'message' => 'Tidak dapat menghapus Administrator BKPSDM terakhir di sistem.'
                ], 400);
            }
        }

        $pengguna->delete(); // Soft delete melalui SoftDeletes trait

        return response()->json([
            'message' => 'Pengguna berhasil dihapus'
        ]);
    }

    public function resetPassword(string $id)
    {
        $pengguna = \App\Models\Pengguna::findOrFail($id);
        $passwordDefault = substr($pengguna->nip, -8);
        
        $pengguna->update([
            'kata_sandi_hash' => \Illuminate\Support\Facades\Hash::make($passwordDefault)
        ]);

        return response()->json([
            'message' => 'Password pengguna berhasil direset ke default (8 digit NIP).'
        ]);
    }
}
