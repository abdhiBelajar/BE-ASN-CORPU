<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Sertifikat;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class SertifikatController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $sertifikat = Sertifikat::whereHas('pendaftaran', function($q) use ($user) {
            $q->where('pengguna_id', $user->pengguna_id);
        })
        ->with(['pendaftaran.pembelajaran' => function($q) {
            $q->with(['pembelajaranJp', 'moduls']);
        }])
        ->orderBy('tanggal_terbit', 'desc')
        ->get()
        ->map(function($s) {
            $pemb = $s->pendaftaran ? $s->pendaftaran->pembelajaran : null;
            $jpSer = $pemb && $pemb->pembelajaranJp ? $pemb->pembelajaranJp->first() : null;
            $thumb = $pemb ? $pemb->thumbnail_url : null;
            if (!$thumb) {
                $thumb = 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?q=80&w=2070&auto=format&fit=crop';
            }
            return [
                'sertifikat_id' => $s->sertifikat_id,
                'pembelajaran_id' => $s->pendaftaran->pembelajaran_id ?? null,
                'nomor_sertifikat' => $s->nomor_sertifikat,
                'tanggal_terbit' => $s->tanggal_terbit,
                'judul_pelatihan' => $pemb->judul_pembelajaran ?? '-',
                'jpl' => $jpSer ? $jpSer->jp_final : 0,
                'kategori' => $pemb->kategori ?? 'Lainnya',
                'tautan_berkas' => $s->tautan_berkas,
                'image' => $thumb,
                'thumbnail_url' => $thumb,
            ];
        });

        return response()->json([
            'message' => 'Daftar sertifikat berhasil diambil',
            'data' => $sertifikat
        ]);
    }

    public function download(Request $request, $id)
    {
        $user = $request->user();

        $sertifikat = Sertifikat::where('sertifikat_id', $id)
            ->whereHas('pendaftaran', function($q) use ($user) {
                $q->where('pengguna_id', $user->pengguna_id);
            })
            ->with(['pendaftaran.pembelajaran' => function($q) {
                $q->with('pembelajaranJp');
            }])
            ->first();

        if (!$sertifikat) {
            return response()->json(['message' => 'Sertifikat tidak ditemukan atau Anda tidak memiliki akses'], 404);
        }

        return $this->generatePdfResponse($sertifikat);
    }

    public function downloadByCourse(Request $request, $pembelajaranId)
    {
        $user = $request->user();

        // Cari sertifikat milik user untuk pembelajaran ini
        $sertifikat = Sertifikat::whereHas('pendaftaran', function($q) use ($user, $pembelajaranId) {
                $q->where('pengguna_id', $user->pengguna_id)
                  ->where('pembelajaran_id', $pembelajaranId);
            })
            ->with(['pendaftaran.pembelajaran' => function($q) {
                $q->with('pembelajaranJp');
            }])
            ->first();

        if (!$sertifikat) {
            return response()->json(['message' => 'Sertifikat tidak ditemukan atau Anda tidak memiliki akses'], 404);
        }

        return $this->generatePdfResponse($sertifikat);
    }

    public function downloadAdmin(Request $request, $id)
    {
        $sertifikat = Sertifikat::with(['pendaftaran.pembelajaran' => function($q) {
            $q->with('pembelajaranJp');
        }])->find($id);

        if (!$sertifikat) {
            return response()->json(['message' => 'Sertifikat tidak ditemukan'], 404);
        }

        return $this->generatePdfResponse($sertifikat);
    }

    private function generatePdfResponse($sertifikat)
    {
        $certService = app(\App\Services\CertificateService::class);
        $pdf = $certService->renderPdf($sertifikat);

        $cleanName = str_replace(' ', '_', $sertifikat->nama_lengkap_snapshot ?? 'Peserta');
        return $pdf->download('Sertifikat_' . $cleanName . '.pdf');
    }
}
