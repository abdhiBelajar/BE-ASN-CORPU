<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sertifikat;
use App\Models\ValidasiSertifikat;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CertificateVerificationController extends Controller
{
    /**
     * Verifikasi keaslian sertifikat secara publik saat QR Code di-scan atau dicari via ID
     */
    public function verify(Request $request, $code)
    {
        $cleanCode = trim(urldecode($code));

        $sertifikat = Sertifikat::where('verification_code', $cleanCode)
            ->orWhere('nomor_sertifikat', $cleanCode)
            ->with(['pendaftaran.pembelajaran.pembelajaranJp', 'pendaftaran.pengguna'])
            ->first();

        if (!$sertifikat) {
            return response()->json([
                'is_valid' => false,
                'status' => 'tidak_valid',
                'message' => 'Sertifikat tidak terdaftar atau tidak sah dalam pangkalan data BKPSDM Kabupaten Buleleng.',
                'data' => null
            ], 404);
        }

        // Deteksi jenis perangkat dari User-Agent
        $userAgent = $request->userAgent() ?? '';
        $deviceType = 'Desktop';
        if (preg_match('/(android|iphone|ipad|mobile|tablet)/i', $userAgent)) {
            $deviceType = preg_match('/(tablet|ipad)/i', $userAgent) ? 'Tablet' : 'Mobile';
        }

        // Catat ke tabel validasi_sertifikat setiap kali di-scan
        ValidasiSertifikat::create([
            'sertifikat_id' => $sertifikat->sertifikat_id,
            'nomor_sertifikat' => $sertifikat->nomor_sertifikat,
            'ip_address' => $request->ip(),
            'user_agent' => substr($userAgent, 0, 500),
            'perangkat' => $deviceType,
            'status_validasi' => 'valid',
            'scanned_at' => now(),
        ]);

        $totalScans = ValidasiSertifikat::where('sertifikat_id', $sertifikat->sertifikat_id)->count();

        $pembelajaran = $sertifikat->pendaftaran ? $sertifikat->pendaftaran->pembelajaran : null;
        $pengguna = $sertifikat->pendaftaran ? $sertifikat->pendaftaran->pengguna : null;
        $jpDet = $pembelajaran && $pembelajaran->pembelajaranJp ? $pembelajaran->pembelajaranJp->first() : null;

        $durasiJp = $jpDet && $jpDet->jp_final ? (float)$jpDet->jp_final : 0;
        $durasiJpFormatted = (int)$durasiJp == $durasiJp ? (int)$durasiJp : $durasiJp;

        $tglTerbit = Carbon::parse($sertifikat->tanggal_terbit)->locale('id');

        return response()->json([
            'is_valid' => true,
            'status' => 'valid',
            'message' => 'Sertifikat Resmi & Sah Terdaftar di BKPSDM Kabupaten Buleleng',
            'data' => [
                'sertifikat_id' => $sertifikat->sertifikat_id,
                'nomor_sertifikat' => $sertifikat->nomor_sertifikat,
                'verification_code' => $sertifikat->verification_code,
                'nama_lengkap' => $sertifikat->nama_lengkap_snapshot ?: ($pengguna->nama_lengkap ?? '-'),
                'nip' => $sertifikat->nip_snapshot ?: ($pengguna->nip ?? '-'),
                'unit_kerja' => $sertifikat->unit_kerja_snapshot ?: ($pengguna->unit_kerja ?? 'Pemerintah Kabupaten Buleleng'),
                'judul_pelatihan' => $pembelajaran ? $pembelajaran->judul_pembelajaran : '-',
                'durasi_jp' => $durasiJpFormatted,
                'tanggal_terbit' => $tglTerbit->translatedFormat('d F Y'),
                'pejabat_nama' => 'I MADE DWI ADNYANA, S.STP., M.A.P',
                'pejabat_nip' => '197612281996011001',
                'pejabat_jabatan' => 'Kepala BKPSDM Kabupaten Buleleng',
                'total_verifikasi' => $totalScans,
                'waktu_verifikasi_ini' => now()->locale('id')->translatedFormat('d F Y H:i:s') . ' WITA'
            ]
        ]);
    }

    /**
     * Riwayat Pemindaian & Validasi Sertifikat untuk Monitoring Admin BKPSDM
     */
    public function riwayatValidasi(Request $request)
    {
        $query = ValidasiSertifikat::with(['sertifikat.pendaftaran.pembelajaran', 'sertifikat.pendaftaran.pengguna'])
            ->orderBy('scanned_at', 'desc');

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('nomor_sertifikat', 'like', "%{$search}%")
                  ->orWhere('ip_address', 'like', "%{$search}%")
                  ->orWhereHas('sertifikat', function($sq) use ($search) {
                      $sq->where('nama_lengkap_snapshot', 'like', "%{$search}%")
                        ->orWhere('nip_snapshot', 'like', "%{$search}%");
                  });
            });
        }

        $perPage = $request->get('per_page', 15);
        $logs = $query->paginate($perPage);

        $totalValidasi = ValidasiSertifikat::count();
        $totalSertifikatDivalidasi = ValidasiSertifikat::distinct('sertifikat_id')->count('sertifikat_id');
        $validasiHariIni = ValidasiSertifikat::whereDate('scanned_at', today())->count();

        return response()->json([
            'message' => 'Riwayat validasi sertifikat berhasil diambil',
            'data' => $logs,
            'stats' => [
                'total_validasi' => $totalValidasi,
                'sertifikat_unik' => $totalSertifikatDivalidasi,
                'validasi_hari_ini' => $validasiHariIni,
            ]
        ]);
    }
}
