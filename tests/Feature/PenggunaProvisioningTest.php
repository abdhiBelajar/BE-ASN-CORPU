<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\PegawaiSimpeg;
use App\Models\Pengguna;
use App\Models\PenggunaPeran;
use App\Services\PenggunaProvisioningService;
use Illuminate\Support\Facades\Hash;

class PenggunaProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected PenggunaProvisioningService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PenggunaProvisioningService::class);
    }

    /**
     * 1. Provisioning membuat pengguna (peran peserta, status aktif, kata_sandi_diatur_pada null)
     * dan satu baris pengguna_peran untuk tiap pegawai_simpegs.
     */
    public function test_provisioning_creates_pengguna_and_pengguna_peran(): void
    {
        $pegawai = PegawaiSimpeg::create([
            'nip' => '199001012020011001',
            'nama_lengkap' => 'Pegawai Uji Satu',
            'jabatan' => 'Pranata Komputer',
            'rumpun_jabatan' => 'JF',
            'unit_kerja' => 'BKPSDM',
            'email' => 'pegawai1@bulelengkab.go.id',
        ]);

        $status = $this->service->provision($pegawai);

        $this->assertEquals('dibuat', $status);

        $pengguna = Pengguna::where('nip', '199001012020011001')->first();
        $this->assertNotNull($pengguna);
        $this->assertEquals('peserta', $pengguna->peran);
        $this->assertEquals('aktif', $pengguna->status);
        $this->assertNull($pengguna->kata_sandi_diatur_pada);
        $this->assertFalse($pengguna->sudah_aktivasi);
        $this->assertEquals('pegawai1@bulelengkab.go.id', $pengguna->email);

        $this->assertDatabaseHas('pengguna_peran', [
            'pengguna_id' => $pengguna->pengguna_id,
            'peran' => 'peserta',
        ]);
    }

    /**
     * 2. Provisioning dijalankan dua kali tidak membuat duplikat,
     * dan tidak mengubah kata_sandi_hash, peran, atau status akun yang sudah ada.
     */
    public function test_provisioning_idempotent_and_does_not_modify_credentials_or_role(): void
    {
        $pegawai = PegawaiSimpeg::create([
            'nip' => '199001012020011002',
            'nama_lengkap' => 'Pegawai Asli',
            'jabatan' => 'Staff',
            'rumpun_jabatan' => 'JP',
            'unit_kerja' => 'Dinas Pendidikan',
            'email' => 'asli@bulelengkab.go.id',
        ]);

        $this->service->provision($pegawai);

        $pengguna = Pengguna::where('nip', '199001012020011002')->first();
        // Ubah peran dan set kata sandi khusus
        $pengguna->peran = 'admin_komunitas';
        $pengguna->status = 'aktif';
        $customHash = Hash::make('CustomSecretPassword123!');
        $pengguna->kata_sandi_hash = $customHash;
        $pengguna->kata_sandi_diatur_pada = now();
        $pengguna->save();

        // Update nama dan jabatan di SIMPEG
        $pegawai->update([
            'nama_lengkap' => 'Pegawai Diperbarui',
            'jabatan' => 'Kepala Seksi',
        ]);

        // Jalankan provisioning lagi
        $statusSecond = $this->service->provision($pegawai);

        $this->assertEquals('diperbarui', $statusSecond);

        $fresh = $pengguna->fresh();
        $this->assertEquals('Pegawai Diperbarui', $fresh->nama_lengkap);
        $this->assertEquals('Kepala Seksi', $fresh->jabatan);
        // Kredensial, peran, status tidak boleh berubah
        $this->assertEquals($customHash, $fresh->kata_sandi_hash);
        $this->assertEquals('admin_komunitas', $fresh->peran);
        $this->assertNotNull($fresh->kata_sandi_diatur_pada);
        $this->assertEquals(1, Pengguna::where('nip', '199001012020011002')->count());
    }

    /**
     * 3. Akun yang soft-deleted tidak dihidupkan kembali (dilewati).
     */
    public function test_soft_deleted_account_is_skipped(): void
    {
        $pegawai = PegawaiSimpeg::create([
            'nip' => '199001012020011003',
            'nama_lengkap' => 'Pegawai Dihapus',
            'jabatan' => 'Staff',
            'rumpun_jabatan' => 'JP',
            'unit_kerja' => 'Dinas Sosial',
        ]);

        $this->service->provision($pegawai);

        $pengguna = Pengguna::where('nip', '199001012020011003')->first();
        $pengguna->delete(); // Soft delete
        $this->assertTrue($pengguna->trashed());

        // Jalankan provisioning lagi
        $status = $this->service->provision($pegawai);
        $this->assertEquals('dilewati', $status);

        $this->assertTrue($pengguna->fresh()->trashed());
    }

    /**
     * 4. Email placeholder lama diganti email SIMPEG; email ganda dengan pengguna lain tidak menyebabkan error.
     */
    public function test_old_placeholder_email_replaced_and_duplicate_email_handled_gracefully(): void
    {
        // Buat akun dengan email placeholder lama
        $nip = '199001012020011004';
        $user = new Pengguna([
            'nip' => $nip,
            'nama_lengkap' => 'User Lama',
            'email' => "{$nip}@asn.bulelengkab.go.id",
            'peran' => 'peserta',
            'status' => 'aktif',
        ]);
        $user->acakKataSandi()->save();

        $pegawai = PegawaiSimpeg::create([
            'nip' => $nip,
            'nama_lengkap' => 'User Lama Update',
            'jabatan' => 'Staff',
            'rumpun_jabatan' => 'JP',
            'unit_kerja' => 'BKPSDM',
            'email' => 'user.resmi@bulelengkab.go.id',
        ]);

        $status = $this->service->provision($pegawai);
        $this->assertEquals('diperbarui', $status);
        $this->assertEquals('user.resmi@bulelengkab.go.id', $user->fresh()->email);

        // Kasus email ganda: ada akun lain yang sudah menggunakan email tertentu
        $otherUser = new Pengguna([
            'nip' => '199001012020011099',
            'nama_lengkap' => 'User Lain',
            'email' => 'bentrok@bulelengkab.go.id',
            'peran' => 'peserta',
            'status' => 'aktif',
        ]);
        $otherUser->acakKataSandi()->save();

        // Pegawai baru mencoba menggunakan email yang sama
        $pegawaiBentrok = PegawaiSimpeg::create([
            'nip' => '199001012020011005',
            'nama_lengkap' => 'User Baru Bentrok',
            'jabatan' => 'Staff',
            'rumpun_jabatan' => 'JP',
            'unit_kerja' => 'BKPSDM',
            'email' => 'bentrok@bulelengkab.go.id',
        ]);

        $statusBentrok = $this->service->provision($pegawaiBentrok);
        $this->assertEquals('dibuat', $statusBentrok);

        $createdUser = Pengguna::where('nip', '199001012020011005')->first();
        $this->assertNull($createdUser->email); // Email di-set null jika bentrok
    }
}
