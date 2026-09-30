<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\PenggunaProvisioningService;

class SyncPenggunaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'simpeg:sync-pengguna {--dry-run : Menjalankan simulasi tanpa menyimpan perubahan ke basis data}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sinkronisasi data pegawai SIMPEG ke tabel akun pengguna LMS';

    /**
     * Execute the console command.
     */
    public function handle(PenggunaProvisioningService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? 'Memulai simulasi sinkronisasi pengguna (dry-run)...' : 'Memulai sinkronisasi data pegawai SIMPEG ke akun pengguna...');

        $stats = $service->provisionSemua($dryRun);

        $this->newLine();
        $this->info('Ringkasan Sinkronisasi Pengguna:');
        $this->table(
            ['Status', 'Jumlah Akun'],
            [
                ['Akun Dibuat', $stats['dibuat']],
                ['Akun Diperbarui', $stats['diperbarui']],
                ['Akun Dilewati', $stats['dilewati']],
                ['Akun Tanpa Email (Perlu dilengkapi)', $stats['tanpa_email']],
            ]
        );

        if ($dryRun) {
            $this->warn('Mode simulasi aktif: Tidak ada perubahan yang disimpan ke basis data.');
        } else {
            $this->info('Sinkronisasi selesai.');
        }

        return 0;
    }
}
