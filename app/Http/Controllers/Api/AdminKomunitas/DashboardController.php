<?php

namespace App\Http\Controllers\Api\AdminKomunitas;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AdminKomunitas;
use App\Models\Pembelajaran;
use App\Models\PendaftaranPembelajaran;
use App\Models\Sertifikat;
use App\Models\RiwayatPostTest;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $komunitasIds = $user->komunitasKelolaIds();
        $pembelajaranList = Pembelajaran::whereIn('komunitas_id', $komunitasIds)->get();
        $pembelajaranIds = $pembelajaranList->pluck('pembelajaran_id');

        $allPendaftaran = PendaftaranPembelajaran::whereIn('pembelajaran_id', $pembelajaranIds)->get();
        $pendaftaranIds = $allPendaftaran->pluck('pendaftaran_id');

        $totalPeserta = $allPendaftaran->unique('pengguna_id')->count();
        $aktif = $pembelajaranList->where('status', 'dipublikasikan')->count();
        $rataProgres = $allPendaftaran->count() > 0 ? round($allPendaftaran->avg('persentase_progres') ?? 0, 1) : 0;
        $sertifikat = Sertifikat::whereIn('pendaftaran_id', $pendaftaranIds)->count();

        // 5 Kursus terbaru dengan data pendaftar & progres riil
        $kursusTerbaru = Pembelajaran::whereIn('komunitas_id', $komunitasIds)
            ->latest('dibuat_pada')
            ->take(5)
            ->get()
            ->map(function ($c) {
                $enrolled = PendaftaranPembelajaran::where('pembelajaran_id', $c->pembelajaran_id)->count();
                $avgProg = PendaftaranPembelajaran::where('pembelajaran_id', $c->pembelajaran_id)->avg('persentase_progres') ?? 0;
                $c->total_peserta = $enrolled;
                $c->rata_rata_progres = round($avgProg, 1);
                return $c;
            });

        // Aktivitas terkini dari pendaftaran & riwayat evaluasi
        $aktivitas = PendaftaranPembelajaran::whereIn('pembelajaran_id', $pembelajaranIds)
            ->with(['pengguna:pengguna_id,nama_lengkap', 'pembelajaran:pembelajaran_id,judul_pembelajaran'])
            ->latest('terdaftar_pada')
            ->take(5)
            ->get()
            ->map(function ($p) {
                $timeAgo = $p->terdaftar_pada ? \Carbon\Carbon::parse($p->terdaftar_pada)->diffForHumans() : 'Baru saja';
                $action = 'terdaftar di kursus ' . ($p->pembelajaran->judul_pembelajaran ?? '');
                if ($p->status_pendaftaran === 'lulus' || $p->status_pendaftaran === 'selesai') {
                    $action = 'telah menyelesaikan kursus ' . ($p->pembelajaran->judul_pembelajaran ?? '');
                }

                return [
                    'name' => $p->pengguna->nama_lengkap ?? 'Peserta ASN',
                    'action' => $action,
                    'time' => $timeAgo,
                    'status' => $p->status_pendaftaran
                ];
            });

        // Tren penyelesaian (6 bulan terakhir)
        $sixMonthsAgo = \Carbon\Carbon::now()->subMonths(5)->startOfMonth();
        $trendData = PendaftaranPembelajaran::whereIn('pembelajaran_id', $pembelajaranIds)
            ->whereIn('status_pendaftaran', ['selesai', 'lulus'])
            ->whereNotNull('diselesaikan_pada')
            ->where('diselesaikan_pada', '>=', $sixMonthsAgo)
            ->selectRaw('MONTH(diselesaikan_pada) as month, YEAR(diselesaikan_pada) as year, COUNT(*) as count')
            ->groupBy('year', 'month')
            ->orderBy('year', 'asc')
            ->orderBy('month', 'asc')
            ->get();
            
        $tren_penyelesaian = [];
        $months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        
        for ($i = 5; $i >= 0; $i--) {
            $date = \Carbon\Carbon::now()->subMonths($i);
            $tren_penyelesaian[$date->format('Y-n')] = [
                'month' => $months[$date->month - 1],
                'count' => 0,
                'percent' => 0
            ];
        }
        
        $maxCount = $trendData->max('count') ?: 1;
        
        foreach ($trendData as $data) {
            $key = $data->year . '-' . $data->month;
            if (isset($tren_penyelesaian[$key])) {
                $tren_penyelesaian[$key]['count'] = $data->count;
                $tren_penyelesaian[$key]['percent'] = round(($data->count / $maxCount) * 100);
            }
        }

        return response()->json([
            'message' => 'Data dashboard berhasil diambil',
            'data' => [
                'stats' => [
                    'totalPeserta' => $totalPeserta,
                    'aktif' => $aktif,
                    'rataProgres' => $rataProgres,
                    'sertifikat' => $sertifikat,
                ],
                'kursus_terbaru' => $kursusTerbaru,
                'aktivitas_terkini' => $aktivitas,
                'tren_penyelesaian' => array_values($tren_penyelesaian)
            ]
        ]);
    }
}
