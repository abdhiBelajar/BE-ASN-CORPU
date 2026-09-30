<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Pengguna;
use Illuminate\Support\Facades\Hash;

class AmankanSandiLamaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auth:amankan-sandi-lama 
                            {--semua : Acak sandi seluruh pengguna kecuali admin_bkpsdm} 
                            {--dry-run : Menjalankan simulasi tanpa menyimpan perubahan}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mengamankan akun dengan sandi default lama yang mudah ditebak';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $semua = (bool) $this->option('semua');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Menjalankan dalam mode simulasi (dry-run). Basis data tidak akan diubah.');
        }

        if (!$semua) {
            $this->warn('Perhatian: Proses verifikasi bcrypt terhadap kata sandi lama dapat memakan waktu beberapa saat.');
        }

        $totalChecked = 0;
        $totalSecured = 0;

        $query = Pengguna::query();

        if ($semua) {
            // Seluruh pengguna kecuali yang memiliki peran admin_bkpsdm
            $query->where('peran', '!=', 'admin_bkpsdm')
                ->whereDoesntHave('daftarPeran', fn ($q) => $q->where('peran', 'admin_bkpsdm'));

            $query->chunkById(200, function ($users) use (&$totalChecked, &$totalSecured, $dryRun) {
                foreach ($users as $user) {
                    $totalChecked++;
                    $totalSecured++;
                    if (!$dryRun) {
                        $user->acakKataSandi()->save();
                        $user->tokens()->delete();
                    }
                }
            });
        } else {
            $query->chunkById(200, function ($users) use (&$totalChecked, &$totalSecured, $dryRun) {
                foreach ($users as $user) {
                    $totalChecked++;
                    $nip = $user->nip;
                    $last8 = strlen($nip) >= 8 ? substr($nip, -8) : $nip;
                    $candidates = array_unique([$nip, $last8, 'admin123']);

                    $match = false;
                    foreach ($candidates as $cand) {
                        if (Hash::check($cand, $user->kata_sandi_hash)) {
                            $match = true;
                            break;
                        }
                    }

                    if ($match) {
                        $totalSecured++;
                        if (!$dryRun) {
                            $user->acakKataSandi()->save();
                            $user->tokens()->delete();
                        }
                    }
                }
            });
        }

        $this->newLine();
        $this->info("Pemeriksaan selesai.");
        $this->line("- Total akun diperiksa: {$totalChecked}");
        $this->line("- Total akun yang diamankan (sandi diacak & sesi dicabut): {$totalSecured}");

        return 0;
    }
}
