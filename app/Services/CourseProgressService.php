<?php

namespace App\Services;

use App\Models\PendaftaranPembelajaran;
use App\Models\Materi;
use App\Models\Kuis;
use App\Models\ProgresMateri;
use App\Models\RiwayatKuis;
use App\Models\RiwayatPostTest;
use App\Models\Sertifikat;

class CourseProgressService
{
    /**
     * Sinkronisasi progres peserta berdasarkan modul dan materi terbaru dalam pembelajaran.
     * Jika modul diperbarui dan progres peserta menjadi < 100%, status lulus dihapus,
     * sertifikat & riwayat post test dibatalkan, dan peserta harus melanjutkan belajar.
     */
    public static function syncUserProgress(PendaftaranPembelajaran $pendaftaran)
    {
        $pembelajaranId = $pendaftaran->pembelajaran_id;

        // Ambil ID semua materi aktif dalam pembelajaran ini
        $courseMateriIds = Materi::whereHas('modul', function($q) use ($pembelajaranId) {
            $q->where('pembelajaran_id', $pembelajaranId);
        })->pluck('materi_id');

        // Ambil ID semua kuis modul dalam pembelajaran ini
        $courseKuisIds = Kuis::whereHas('modul', function($q) use ($pembelajaranId) {
            $q->where('pembelajaran_id', $pembelajaranId);
        })->pluck('kuis_id');

        $totalItem = $courseMateriIds->count() + $courseKuisIds->count();

        // Materi yang sudah diselesaikan peserta pada kursus ini
        $materiSelesai = ProgresMateri::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
            ->where('apakah_selesai', true)
            ->whereIn('materi_id', $courseMateriIds)
            ->count();

        // Kuis yang sudah lulus pada kursus ini
        $kuisLulus = RiwayatKuis::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
            ->where('apakah_lulus', true)
            ->whereIn('kuis_id', $courseKuisIds)
            ->distinct('kuis_id')
            ->count('kuis_id');

        $itemSelesai = $materiSelesai + $kuisLulus;
        $persentase = $totalItem > 0 ? round(($itemSelesai / $totalItem) * 100, 2) : 0;

        $isDirty = false;

        // Cek jika persentase berbeda
        if ((float)$pendaftaran->persentase_progres !== (float)$persentase) {
            $pendaftaran->persentase_progres = $persentase;
            $isDirty = true;
        }

        // Jika persentase progres belum mencapai 100%:
        // Walaupun peserta sebelumnya berstatus lulus / selesai, status tersebut harus dihapus
        // dan diubah menjadi sedang_berjalan / terdaftar agar peserta melanjutkan pembelajaran modul baru.
        if ($persentase < 100) {
            if (in_array($pendaftaran->status_pendaftaran, ['lulus', 'selesai', 'menunggu_post_test'])) {
                $pendaftaran->status_pendaftaran = ($persentase > 0) ? 'sedang_berjalan' : 'terdaftar';
                $pendaftaran->diselesaikan_pada = null;
                $isDirty = true;

                // Hapus sertifikat & riwayat post test yang sebelumnya agar peserta mengulang post test setelah 100%
                Sertifikat::where('pendaftaran_id', $pendaftaran->pendaftaran_id)->delete();
                RiwayatPostTest::where('pendaftaran_id', $pendaftaran->pendaftaran_id)->delete();
            }
        } elseif ($persentase >= 100) {
            // Cek apakah ada riwayat kelulusan post test yang sah
            $hasPassedPostTest = RiwayatPostTest::where('pendaftaran_id', $pendaftaran->pendaftaran_id)
                ->where('apakah_lulus', true)
                ->exists();

            if (!$hasPassedPostTest) {
                // Jika belum lulus post test yang aktif (atau post test direset karena modul diedit)
                if ($pendaftaran->status_pendaftaran !== 'menunggu_post_test') {
                    $pendaftaran->status_pendaftaran = 'menunggu_post_test';
                    $pendaftaran->diselesaikan_pada = null;
                    $isDirty = true;

                    // Hapus sertifikat jika belum lulus post test yang sah
                    Sertifikat::where('pendaftaran_id', $pendaftaran->pendaftaran_id)->delete();
                }
            } else {
                // Jika sudah ada riwayat post test lulus yang sah
                if ($pendaftaran->status_pendaftaran !== 'lulus') {
                    $pendaftaran->status_pendaftaran = 'lulus';
                    $isDirty = true;
                }
            }
        }

        if ($isDirty) {
            $pendaftaran->save();
        }

        return $pendaftaran;
    }

    /**
     * Reset status kelulusan seluruh peserta setelah modul/kursus diedit dan divalidasi oleh Admin BKPSDM.
     * Peserta yang sebelumnya sudah lulus harus mengulang Post Test (dan materi baru jika ada penambahan).
     */
    public static function resetCourseCompletionAfterApproval($pembelajaranId)
    {
        $pendaftarans = PendaftaranPembelajaran::where('pembelajaran_id', $pembelajaranId)->get();

        foreach ($pendaftarans as $pendaftaran) {
            // Hapus riwayat post test lama karena ada perubahan modul/materi/soal baru
            RiwayatPostTest::where('pendaftaran_id', $pendaftaran->pendaftaran_id)->delete();

            // Hapus sertifikat lama yang pernah diterbitkan
            Sertifikat::where('pendaftaran_id', $pendaftaran->pendaftaran_id)->delete();

            // Reset timestamp penyelesaian
            $pendaftaran->diselesaikan_pada = null;

            // Jika sebelumnya sudah lulus / selesai, kembalikan ke status aktif
            if (in_array($pendaftaran->status_pendaftaran, ['lulus', 'selesai'])) {
                $pendaftaran->status_pendaftaran = 'sedang_berjalan';
            }

            $pendaftaran->save();

            // Sinkronisasi ulang persentase progres dan status kelulusan terbaru
            self::syncUserProgress($pendaftaran);
        }
    }

    /**
     * Sinkronisasi seluruh peserta yang terdaftar pada suatu kursus
     */
    public static function syncCourseParticipants($pembelajaranId)
    {
        $pendaftarans = PendaftaranPembelajaran::where('pembelajaran_id', $pembelajaranId)->get();
        foreach ($pendaftarans as $pendaftaran) {
            self::syncUserProgress($pendaftaran);
        }
    }
}
