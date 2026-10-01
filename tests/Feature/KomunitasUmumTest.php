<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Pengguna;
use App\Models\PenggunaPeran;
use App\Models\Komunitas;
use App\Models\AdminKomunitas;
use App\Models\Pembelajaran;
use App\Models\Modul;
use App\Models\Materi;
use App\Models\Kuis;
use App\Models\PostTest;
use Illuminate\Support\Facades\Hash;
use Database\Seeders\KomunitasUmumSeeder;

class KomunitasUmumTest extends TestCase
{
    use RefreshDatabase;

    protected Pengguna $adminBkpsdm;
    protected Komunitas $komunitasUmum;
    protected Komunitas $komunitasJPT;
    protected Komunitas $komunitasJF;
    protected Pengguna $pesertaJF;
    protected Pengguna $pesertaJPT;
    protected Pengguna $pesertaJP;
    protected Pengguna $adminKomunitasJF;
    protected Pengguna $adminKomunitasJPT;

    protected function setUp(): void
    {
        parent::setUp();

        Komunitas::resetUmumIdCache();

        // 1. Admin BKPSDM
        $this->adminBkpsdm = Pengguna::create([
            'nip' => 'root',
            'nama_lengkap' => 'Admin BKPSDM',
            'email' => 'admin@bkpsdm.go.id',
            'kata_sandi_hash' => Hash::make('Ppir00tlm5123!'),
            'kata_sandi_diatur_pada' => now(),
            'peran' => 'admin_bkpsdm',
            'status' => 'aktif',
        ]);
        PenggunaPeran::create([
            'pengguna_id' => $this->adminBkpsdm->pengguna_id,
            'peran' => 'admin_bkpsdm'
        ]);

        // Seed Komunitas Umum
        $this->seed(KomunitasUmumSeeder::class);
        $this->komunitasUmum = Komunitas::query()->umum()->first();

        // 2. Komunitas Rumpun JPT
        $this->komunitasJPT = Komunitas::create([
            'nama_komunitas' => 'Komunitas JPT',
            'deskripsi' => 'Komunitas untuk Jabatan Pimpinan Tinggi',
            'rumpun_jabatan' => 'JPT',
            'dibuat_oleh_pengguna_id' => $this->adminBkpsdm->pengguna_id,
            'status' => 'aktif',
        ]);

        // 3. Komunitas Rumpun JF
        $this->komunitasJF = Komunitas::create([
            'nama_komunitas' => 'Komunitas JF',
            'deskripsi' => 'Komunitas untuk Jabatan Fungsional',
            'rumpun_jabatan' => 'JF',
            'dibuat_oleh_pengguna_id' => $this->adminBkpsdm->pengguna_id,
            'status' => 'aktif',
        ]);

        // 4. Peserta JF
        $this->pesertaJF = Pengguna::create([
            'nip' => '199001012020011010',
            'nama_lengkap' => 'Peserta JF',
            'email' => 'peserta_jf@bkpsdm.go.id',
            'kata_sandi_hash' => Hash::make('Password123!'),
            'kata_sandi_diatur_pada' => now(),
            'peran' => 'peserta',
            'rumpun_jabatan' => 'JF',
            'status' => 'aktif',
        ]);
        PenggunaPeran::create(['pengguna_id' => $this->pesertaJF->pengguna_id, 'peran' => 'peserta']);

        // 5. Peserta JPT
        $this->pesertaJPT = Pengguna::create([
            'nip' => '198001012005011001',
            'nama_lengkap' => 'Peserta JPT',
            'email' => 'peserta_jpt@bkpsdm.go.id',
            'kata_sandi_hash' => Hash::make('Password123!'),
            'kata_sandi_diatur_pada' => now(),
            'peran' => 'peserta',
            'rumpun_jabatan' => 'JPT',
            'status' => 'aktif',
        ]);
        PenggunaPeran::create(['pengguna_id' => $this->pesertaJPT->pengguna_id, 'peran' => 'peserta']);

        // 6. Peserta JP
        $this->pesertaJP = Pengguna::create([
            'nip' => '199501012020011099',
            'nama_lengkap' => 'Peserta JP',
            'email' => 'peserta_jp@bkpsdm.go.id',
            'kata_sandi_hash' => Hash::make('Password123!'),
            'kata_sandi_diatur_pada' => now(),
            'peran' => 'peserta',
            'rumpun_jabatan' => 'JP',
            'status' => 'aktif',
        ]);
        PenggunaPeran::create(['pengguna_id' => $this->pesertaJP->pengguna_id, 'peran' => 'peserta']);

        // 7. Admin Komunitas JF
        $this->adminKomunitasJF = Pengguna::create([
            'nip' => '198501012010011005',
            'nama_lengkap' => 'Admin Komunitas JF',
            'email' => 'adm_jf@bkpsdm.go.id',
            'kata_sandi_hash' => Hash::make('Password123!'),
            'kata_sandi_diatur_pada' => now(),
            'peran' => 'admin_komunitas',
            'rumpun_jabatan' => 'JF',
            'status' => 'aktif',
        ]);
        PenggunaPeran::create(['pengguna_id' => $this->adminKomunitasJF->pengguna_id, 'peran' => 'admin_komunitas']);
        AdminKomunitas::create([
            'pengguna_id' => $this->adminKomunitasJF->pengguna_id,
            'komunitas_id' => $this->komunitasJF->komunitas_id,
        ]);

        // 8. Admin Komunitas JPT
        $this->adminKomunitasJPT = Pengguna::create([
            'nip' => '198001012005011002',
            'nama_lengkap' => 'Admin Komunitas JPT',
            'email' => 'adm_jpt@bkpsdm.go.id',
            'kata_sandi_hash' => Hash::make('Password123!'),
            'kata_sandi_diatur_pada' => now(),
            'peran' => 'admin_komunitas',
            'rumpun_jabatan' => 'JPT',
            'status' => 'aktif',
        ]);
        PenggunaPeran::create(['pengguna_id' => $this->adminKomunitasJPT->pengguna_id, 'peran' => 'admin_komunitas']);
        AdminKomunitas::create([
            'pengguna_id' => $this->adminKomunitasJPT->pengguna_id,
            'komunitas_id' => $this->komunitasJPT->komunitas_id,
        ]);
    }

    // ==========================================
    // PESERTA TESTS
    // ==========================================

    public function test_peserta_jf_sees_komunitas_umum_as_joined_and_can_join(): void
    {
        $response = $this->actingAs($this->pesertaJF)->getJson('/api/user/komunitas');
        $response->assertOk();

        $data = collect($response->json('data'));
        $umum = $data->firstWhere('id', $this->komunitasUmum->komunitas_id);

        $this->assertNotNull($umum);
        $this->assertTrue($umum['is_umum']);
        $this->assertTrue($umum['is_joined']);
        $this->assertTrue($umum['can_join']);

        // Komunitas Umum muncul pertama
        $this->assertEquals($this->komunitasUmum->komunitas_id, $data->first()['id']);
    }

    public function test_peserta_jf_join_komunitas_umum_succeeds_without_creating_pivot_row(): void
    {
        $response = $this->actingAs($this->pesertaJF)->postJson("/api/user/komunitas/{$this->komunitasUmum->komunitas_id}/join");
        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => 'Komunitas Umum terbuka untuk seluruh pegawai; Anda otomatis terdaftar.'
        ]);

        $this->assertDatabaseMissing('komunitas_pengguna', [
            'pengguna_id' => $this->pesertaJF->pengguna_id,
            'komunitas_id' => $this->komunitasUmum->komunitas_id,
        ]);
    }

    public function test_peserta_jf_join_komunitas_jpt_returns_403(): void
    {
        $response = $this->actingAs($this->pesertaJF)->postJson("/api/user/komunitas/{$this->komunitasJPT->komunitas_id}/join");
        $response->assertStatus(403);
    }

    public function test_peserta_jf_without_joining_any_community_sees_komunitas_umum_courses_in_katalog(): void
    {
        $pembelajaranUmum = Pembelajaran::create([
            'komunitas_id' => $this->komunitasUmum->komunitas_id,
            'judul_pembelajaran' => 'Kursus Dasar ASN Umum',
            'capaian_pembelajaran' => 'Capaian materi umum',
            'ringkasan_materi' => 'Ringkasan materi umum',
            'nilai_kelulusan' => 70,
            'dirancang_oleh_pengguna_id' => $this->adminKomunitasJF->pengguna_id,
            'status' => 'dipublikasikan',
            'dipublikasikan_pada' => now(),
        ]);

        $response = $this->actingAs($this->pesertaJF)->getJson('/api/user/katalog');
        $response->assertOk();
        $response->assertJson(['has_joined_community' => true]);

        $joined = collect($response->json('joined_communities'));
        $this->assertTrue($joined->contains('komunitas_id', $this->komunitasUmum->komunitas_id));

        $courses = collect($response->json('data.data'));
        $this->assertTrue($courses->contains('id', $pembelajaranUmum->pembelajaran_id));
    }

    public function test_peserta_from_different_rumpun_can_enroll_in_komunitas_umum_courses(): void
    {
        $pembelajaranUmum = Pembelajaran::create([
            'komunitas_id' => $this->komunitasUmum->komunitas_id,
            'judul_pembelajaran' => 'Pelatihan Transformasi Digital',
            'capaian_pembelajaran' => 'Kompetensi digital',
            'ringkasan_materi' => 'Ringkasan materi',
            'nilai_kelulusan' => 70,
            'dirancang_oleh_pengguna_id' => $this->adminKomunitasJF->pengguna_id,
            'status' => 'dipublikasikan',
            'dipublikasikan_pada' => now(),
        ]);

        // Peserta JPT enroll -> 200
        $resJpt = $this->actingAs($this->pesertaJPT)->postJson("/api/user/katalog/{$pembelajaranUmum->pembelajaran_id}/enroll");
        $resJpt->assertOk();

        // Peserta JP enroll -> 200
        $resJp = $this->actingAs($this->pesertaJP)->postJson("/api/user/katalog/{$pembelajaranUmum->pembelajaran_id}/enroll");
        $resJp->assertOk();

        $this->assertDatabaseHas('pendaftaran_pembelajaran', [
            'pengguna_id' => $this->pesertaJPT->pengguna_id,
            'pembelajaran_id' => $pembelajaranUmum->pembelajaran_id,
        ]);
        $this->assertDatabaseHas('pendaftaran_pembelajaran', [
            'pengguna_id' => $this->pesertaJP->pengguna_id,
            'pembelajaran_id' => $pembelajaranUmum->pembelajaran_id,
        ]);
    }

    public function test_peserta_jf_enroll_in_komunitas_jpt_course_returns_403(): void
    {
        $pembelajaranJpt = Pembelajaran::create([
            'komunitas_id' => $this->komunitasJPT->komunitas_id,
            'judul_pembelajaran' => 'Kepemimpinan Strategis JPT',
            'capaian_pembelajaran' => 'Kepemimpinan',
            'ringkasan_materi' => 'Ringkasan materi',
            'nilai_kelulusan' => 80,
            'dirancang_oleh_pengguna_id' => $this->adminKomunitasJPT->pengguna_id,
            'status' => 'dipublikasikan',
            'dipublikasikan_pada' => now(),
        ]);

        $response = $this->actingAs($this->pesertaJF)->postJson("/api/user/katalog/{$pembelajaranJpt->pembelajaran_id}/enroll");
        $response->assertStatus(403);
    }

    public function test_dashboard_rekomendasi_includes_umum_and_own_rumpun_not_other_rumpun(): void
    {
        // Kursus Umum
        $kursusUmum = Pembelajaran::create([
            'komunitas_id' => $this->komunitasUmum->komunitas_id,
            'judul_pembelajaran' => 'Etika Publik Umum',
            'capaian_pembelajaran' => 'Etika',
            'ringkasan_materi' => 'Ringkasan materi',
            'nilai_kelulusan' => 70,
            'dirancang_oleh_pengguna_id' => $this->adminKomunitasJF->pengguna_id,
            'status' => 'dipublikasikan',
            'dipublikasikan_pada' => now(),
        ]);

        // Join komunitas JF
        $this->pesertaJF->komunitas()->attach($this->komunitasJF->komunitas_id, ['bergabung_pada' => now()]);

        // Kursus JF
        $kursusJF = Pembelajaran::create([
            'komunitas_id' => $this->komunitasJF->komunitas_id,
            'judul_pembelajaran' => 'Keahlian Fungsional',
            'capaian_pembelajaran' => 'Keahlian',
            'ringkasan_materi' => 'Ringkasan materi',
            'nilai_kelulusan' => 70,
            'dirancang_oleh_pengguna_id' => $this->adminKomunitasJF->pengguna_id,
            'status' => 'dipublikasikan',
            'dipublikasikan_pada' => now(),
        ]);

        // Kursus JPT
        $kursusJPT = Pembelajaran::create([
            'komunitas_id' => $this->komunitasJPT->komunitas_id,
            'judul_pembelajaran' => 'Manajemen Puncak',
            'capaian_pembelajaran' => 'Manajemen',
            'ringkasan_materi' => 'Ringkasan materi',
            'nilai_kelulusan' => 70,
            'dirancang_oleh_pengguna_id' => $this->adminKomunitasJPT->pengguna_id,
            'status' => 'dipublikasikan',
            'dipublikasikan_pada' => now(),
        ]);

        $response = $this->actingAs($this->pesertaJF)->getJson('/api/user/dashboard');
        $response->assertOk();

        $rekomendasiIds = collect($response->json('data.rekomendasi'))->pluck('pembelajaran_id')->all();
        $this->assertContains($kursusUmum->pembelajaran_id, $rekomendasiIds);
        $this->assertContains($kursusJF->pembelajaran_id, $rekomendasiIds);
        $this->assertNotContains($kursusJPT->pembelajaran_id, $rekomendasiIds);
    }

    // ==========================================
    // ADMIN KOMUNITAS TESTS
    // ==========================================

    public function test_admin_komunitas_jf_gets_own_community_first_and_umum_last(): void
    {
        $response = $this->actingAs($this->adminKomunitasJF)->getJson('/api/admin-komunitas/komunitas-saya');
        $response->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertEquals($this->komunitasJF->komunitas_id, $data[0]['komunitas_id']);
        $this->assertFalse($data[0]['is_umum']);
        $this->assertEquals($this->komunitasUmum->komunitas_id, $data[1]['komunitas_id']);
        $this->assertTrue($data[1]['is_umum']);
    }

    public function test_admin_komunitas_jf_can_create_pembelajaran_in_umum_but_not_in_jpt(): void
    {
        // Create in Umum -> 201
        $resUmum = $this->actingAs($this->adminKomunitasJF)->postJson('/api/admin-komunitas/pembelajaran', [
            'komunitas_id' => $this->komunitasUmum->komunitas_id,
            'judul_pembelajaran' => 'Pembelajaran di Komunitas Umum',
            'capaian_pembelajaran' => 'Capaian Umum',
            'nilai_kelulusan' => 75,
        ]);
        $resUmum->assertStatus(201);

        // Create in JPT -> 403
        $resJpt = $this->actingAs($this->adminKomunitasJF)->postJson('/api/admin-komunitas/pembelajaran', [
            'komunitas_id' => $this->komunitasJPT->komunitas_id,
            'judul_pembelajaran' => 'Pembelajaran Ilegal di JPT',
            'capaian_pembelajaran' => 'Capaian Ilegal',
            'nilai_kelulusan' => 75,
        ]);
        $resJpt->assertStatus(403);
    }

    public function test_admin_jf_can_manage_modules_materials_quiz_and_jp_in_umum_course_by_jpt_admin(): void
    {
        config(['komunitas.umum_hanya_pembuat' => false]);

        $pembelajaranUmumByJPT = Pembelajaran::create([
            'komunitas_id' => $this->komunitasUmum->komunitas_id,
            'judul_pembelajaran' => 'Inovasi Pelayanan Umum',
            'capaian_pembelajaran' => 'Inovasi',
            'ringkasan_materi' => 'Ringkasan materi',
            'nilai_kelulusan' => 70,
            'dirancang_oleh_pengguna_id' => $this->adminKomunitasJPT->pengguna_id,
            'status' => 'draft',
        ]);

        // Admin JF creates Modul
        $resModul = $this->actingAs($this->adminKomunitasJF)->postJson("/api/admin-komunitas/pembelajaran/{$pembelajaranUmumByJPT->pembelajaran_id}/modul", [
            'judul_modul' => 'Modul 1 Inovasi',
            'deskripsi' => 'Pengantar inovasi',
        ]);
        $resModul->assertStatus(201);
        $modulId = $resModul->json('data.modul_id');

        // Admin JF creates Materi (video_embed)
        $resMateri = $this->actingAs($this->adminKomunitasJF)->postJson("/api/admin-komunitas/modul/{$modulId}/materi", [
            'judul_materi' => 'Video Pengantar',
            'tipe_materi' => 'video_embed',
            'tautan_atau_berkas_embed' => 'https://www.youtube.com/watch?v=example',
            'durasi_menit' => 20,
        ]);
        $resMateri->assertStatus(201);

        // Admin JF creates Kuis
        $resKuis = $this->actingAs($this->adminKomunitasJF)->postJson("/api/admin-komunitas/modul/{$modulId}/kuis", [
            'judul_kuis' => 'Evaluasi Modul 1',
            'tipe_kuis' => 'evaluasi_modul',
            'durasi_menit' => 15,
            'nilai_kelulusan' => 70,
            'soal' => [
                [
                    'teks_soal' => 'Apa itu inovasi?',
                    'tipe_soal' => 'pilihan_ganda',
                    'kunci_jawaban' => 'A',
                    'pilihan_jawaban_json' => ['A' => 'Jawaban Benar', 'B' => 'Jawaban Salah'],
                ]
            ]
        ]);
        $resKuis->assertStatus(201);

        // Admin JF creates Post-Test
        $resPostTest = $this->actingAs($this->adminKomunitasJF)->postJson("/api/admin-komunitas/pembelajaran/{$pembelajaranUmumByJPT->pembelajaran_id}/post-test", [
            'judul_post_test' => 'Post Test Akhir',
            'durasi_menit' => 30,
            'nilai_kelulusan' => 70,
            'soal' => [
                [
                    'teks_soal' => 'Soal akhir?',
                    'kunci_jawaban' => 'B',
                    'pilihan_jawaban_json' => ['A' => 'Salah', 'B' => 'Benar'],
                ]
            ]
        ]);
        $resPostTest->assertStatus(201);

        // Admin JF submits JP
        $resJp = $this->actingAs($this->adminKomunitasJF)->postJson("/api/admin-komunitas/pembelajaran/{$pembelajaranUmumByJPT->pembelajaran_id}/jp", [
            'jenis_pelatihan' => 'formal',
        ]);
        $resJp->assertStatus(200);
    }

    public function test_with_switch_d5_active_admin_can_only_edit_own_courses_in_umum(): void
    {
        config(['komunitas.umum_hanya_pembuat' => true]);

        $pembelajaranUmumByJPT = Pembelajaran::create([
            'komunitas_id' => $this->komunitasUmum->komunitas_id,
            'judul_pembelajaran' => 'Kursus JPT di Umum',
            'capaian_pembelajaran' => 'Capaian',
            'ringkasan_materi' => 'Ringkasan materi',
            'nilai_kelulusan' => 70,
            'dirancang_oleh_pengguna_id' => $this->adminKomunitasJPT->pengguna_id,
            'status' => 'draft',
        ]);

        // Admin JF can read course
        $resShow = $this->actingAs($this->adminKomunitasJF)->getJson("/api/admin-komunitas/pembelajaran/{$pembelajaranUmumByJPT->pembelajaran_id}");
        $resShow->assertOk();
        $this->assertFalse($resShow->json('data.dapat_dikelola'));

        // Admin JF cannot update course
        $resUpdate = $this->actingAs($this->adminKomunitasJF)->putJson("/api/admin-komunitas/pembelajaran/{$pembelajaranUmumByJPT->pembelajaran_id}", [
            'judul_pembelajaran' => 'Coba Ubah Judul',
        ]);
        $resUpdate->assertStatus(403);

        // Admin JF cannot delete course
        $resDelete = $this->actingAs($this->adminKomunitasJF)->deleteJson("/api/admin-komunitas/pembelajaran/{$pembelajaranUmumByJPT->pembelajaran_id}");
        $resDelete->assertStatus(403);

        // JPT admin (owner) can update course
        $resOwnerUpdate = $this->actingAs($this->adminKomunitasJPT)->putJson("/api/admin-komunitas/pembelajaran/{$pembelajaranUmumByJPT->pembelajaran_id}", [
            'judul_pembelajaran' => 'Judul Baru oleh Pemilik',
        ]);
        $resOwnerUpdate->assertOk();
    }

    public function test_peserta_only_cannot_access_admin_komunitas_endpoints(): void
    {
        $response = $this->actingAs($this->pesertaJF)->getJson('/api/admin-komunitas/pembelajaran');
        $response->assertStatus(403);
    }

    public function test_pembelajaran_umum_submitted_appears_in_admin_bkpsdm_approval(): void
    {
        $pembelajaranUmum = Pembelajaran::create([
            'komunitas_id' => $this->komunitasUmum->komunitas_id,
            'judul_pembelajaran' => 'Menunggu Validasi BKPSDM',
            'capaian_pembelajaran' => 'Capaian',
            'ringkasan_materi' => 'Ringkasan materi',
            'nilai_kelulusan' => 70,
            'dirancang_oleh_pengguna_id' => $this->adminKomunitasJF->pengguna_id,
            'status' => 'menunggu_approval',
        ]);

        $response = $this->actingAs($this->adminBkpsdm)->getJson('/api/admin-bkpsdm/approval');
        $response->assertOk();

        $items = collect($response->json('data'));
        $this->assertTrue($items->contains('pembelajaran_id', $pembelajaranUmum->pembelajaran_id));
    }

    // ==========================================
    // ADMIN BKPSDM TESTS
    // ==========================================

    public function test_admin_bkpsdm_can_create_komunitas_with_rumpun_umum(): void
    {
        $response = $this->actingAs($this->adminBkpsdm)->postJson('/api/admin-bkpsdm/komunitas', [
            'nama_komunitas' => 'Komunitas Literasi Digital Umum',
            'deskripsi' => 'Komunitas umum tambahan',
            'rumpun_jabatan' => 'UMUM',
        ]);

        $response->assertSuccessful();
        $this->assertDatabaseHas('komunitas', [
            'nama_komunitas' => 'Komunitas Literasi Digital Umum',
            'rumpun_jabatan' => 'UMUM',
        ]);
    }

    public function test_admin_bkpsdm_cannot_delete_deactivate_or_change_rumpun_of_main_komunitas_umum(): void
    {
        // Delete Komunitas Umum utama -> 422
        $resDelete = $this->actingAs($this->adminBkpsdm)->deleteJson("/api/admin-bkpsdm/komunitas/{$this->komunitasUmum->komunitas_id}");
        $resDelete->assertStatus(422);
        $resDelete->assertJson(['message' => 'Komunitas Umum utama tidak dapat dihapus.']);

        // Change rumpun of Komunitas Umum utama -> 422
        $resRumpun = $this->actingAs($this->adminBkpsdm)->putJson("/api/admin-bkpsdm/komunitas/{$this->komunitasUmum->komunitas_id}", [
            'rumpun_jabatan' => 'JF',
        ]);
        $resRumpun->assertStatus(422);
        $resRumpun->assertJson(['message' => 'Rumpun jabatan Komunitas Umum utama tidak dapat diubah.']);

        // Deactivate Komunitas Umum utama -> 422
        $resStatus = $this->actingAs($this->adminBkpsdm)->putJson("/api/admin-bkpsdm/komunitas/{$this->komunitasUmum->komunitas_id}", [
            'status' => 'nonaktif',
        ]);
        $resStatus->assertStatus(422);
        $resStatus->assertJson(['message' => 'Komunitas Umum utama tidak dapat dinonaktifkan.']);
    }

    public function test_admin_bkpsdm_cannot_assign_admin_komunitas_with_umum_id(): void
    {
        $response = $this->actingAs($this->adminBkpsdm)->postJson('/api/admin-bkpsdm/pengguna', [
            'nip' => '198701012015011003',
            'nama_lengkap' => 'Admin Coba Umum',
            'email' => 'admin_coba@bkpsdm.go.id',
            'peran' => 'admin_komunitas',
            'komunitas_id' => $this->komunitasUmum->komunitas_id,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Admin Komunitas otomatis dapat mengelola Komunitas Umum; pilih komunitas rumpun.'
        ]);
    }

    public function test_komunitas_umum_seeder_is_idempotent(): void
    {
        $this->seed(KomunitasUmumSeeder::class);
        $this->seed(KomunitasUmumSeeder::class);

        $this->assertEquals(1, Komunitas::query()->umum()->count());
    }
}
