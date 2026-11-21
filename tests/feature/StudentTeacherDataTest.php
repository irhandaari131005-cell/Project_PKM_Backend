<?php

use App\Database\Migrations\AddClassHomeroomTeacher;
use App\Database\Migrations\AddProfileFields;
use App\Database\Migrations\AddStudentTeacherIdentifiers;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;

require_once APPPATH . 'Database/Migrations/2026-11-04-000002_AddClassHomeroomTeacher.php';
require_once APPPATH . 'Database/Migrations/2026-11-05-000002_AddProfileFields.php';
require_once APPPATH . 'Database/Migrations/2026-11-05-000004_AddStudentTeacherIdentifiers.php';

/** Tambah Siswa/Guru, pencarian berhalaman, privasi, dan penyambungan akun saat registrasi. */
final class StudentTeacherDataTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const GURU = '11111111-1111-4111-8111-111111111111';
    private const SISWA = '22222222-2222-4222-8222-222222222222';

    private BaseConnection $isolatedDatabase;
    private array $originalConnections;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnections = Database::getConnections();
        $this->isolatedDatabase = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'std_', 'DBDebug' => true,
        ], false);
        $this->setPrivateProperty(Database::class, 'instances', ['tests' => $this->isolatedDatabase]);
        $db = $this->isolatedDatabase;
        $db->query('PRAGMA foreign_keys = ON');
        $db->query('CREATE TABLE std_users (id_user VARCHAR(36) PRIMARY KEY, username TEXT UNIQUE, nama TEXT, password TEXT,
            sn TEXT, type TEXT, created_date TEXT, update_date TEXT)');
        $db->query('CREATE TABLE std_kelas (id_kelas INTEGER PRIMARY KEY AUTOINCREMENT, nama_kelas VARCHAR(100) NOT NULL,
            id_jurusan INTEGER, tahun_ajaran DATE, status VARCHAR(100))');
        $db->query('CREATE TABLE std_siswa (id_siswa INTEGER PRIMARY KEY AUTOINCREMENT, id_user VARCHAR(36),
            nama_siswa TEXT NOT NULL, status TEXT, sn TEXT, type TEXT NOT NULL, created_date TEXT, update_date TEXT,
            id_kelas INTEGER REFERENCES std_kelas(id_kelas) ON DELETE SET NULL)');
        $db->query('CREATE TABLE std_guru (id_user VARCHAR(36) PRIMARY KEY, nama TEXT NOT NULL, sn TEXT, type TEXT,
            status TEXT NOT NULL, created_date TEXT, update_date TEXT)');
        foreach ([AddClassHomeroomTeacher::class, AddProfileFields::class, AddStudentTeacherIdentifiers::class] as $migration) {
            (new $migration(Database::forge($db)))->up();
        }
        $db->table('users')->insertBatch([
            ['id_user' => self::GURU, 'username' => 'guru', 'nama' => 'Bu Ani', 'password' => 'x', 'sn' => 'G-01', 'type' => 'guru'],
            ['id_user' => self::SISWA, 'username' => 'siswa', 'nama' => 'Andi', 'password' => 'x', 'sn' => 'S-01', 'type' => 'siswa'],
        ]);
        $db->table('kelas')->insertBatch([
            ['id_kelas' => 1, 'nama_kelas' => 'X RPL 1', 'status' => 'aktif'],
            ['id_kelas' => 2, 'nama_kelas' => 'X RPL 2', 'status' => 'aktif'],
        ]);
        $db->table('guru')->insert([
            'id_user' => self::GURU, 'nama' => 'Bu Ani', 'sn' => 'G-01', 'type' => 'guru', 'status' => 'ACTIVE',
            'nip' => '1980', 'mata_pelajaran' => 'Matematika', 'alamat' => 'Jl. Rahasia Guru', 'no_telp' => '0811111111',
        ]);
        $db->table('siswa')->insertBatch([
            ['id_user' => self::SISWA, 'nama_siswa' => 'Andi', 'sn' => 'S-01', 'type' => 'siswa', 'status' => 'ACTIVE',
                'id_kelas' => 1, 'nis' => '1001', 'alamat' => 'Jl. Rahasia Siswa', 'no_telp' => '0822222222'],
            ['id_user' => null, 'nama_siswa' => 'Budi', 'sn' => null, 'type' => 'siswa', 'status' => 'ACTIVE',
                'id_kelas' => 2, 'nis' => '1002', 'alamat' => null, 'no_telp' => null],
        ]);
    }

    protected function tearDown(): void
    {
        $this->isolatedDatabase->close();
        $this->setPrivateProperty(Database::class, 'instances', $this->originalConnections);
        parent::tearDown();
    }

    // ---- Daftar + pencarian -----------------------------------------------------------

    public function testStudentListSearchesNameOrNisWithClassNameAndSummary(): void
    {
        $body = $this->body($this->asGuru()->get('/api/data_siswa?page=1&search=100'));
        $this->assertSame(['Andi', 'Budi'], array_column($body['data'], 'nama_siswa'));
        $this->assertSame(['X RPL 1', 'X RPL 2'], array_column($body['data'], 'nama_kelas'));
        $this->assertSame(['total_siswa' => 2, 'total_kelas' => 2], $body['meta']['summary']);
        $this->assertSame(1, $body['meta']['last_page']);

        $this->assertSame(['Budi'], array_column($this->body($this->asGuru()->get('/api/data_siswa?page=1&search=bud'))['data'], 'nama_siswa'));
        $this->assertSame(['Budi'], array_column($this->body($this->asGuru()->get('/api/data_siswa?page=1&search=1002'))['data'], 'nama_siswa'));
        $this->assertSame([], $this->body($this->asGuru()->get('/api/data_siswa?page=1&search=%25'))['data']);
    }

    public function testTeacherListSearchesNameOrNip(): void
    {
        $body = $this->body($this->asGuru()->get('/api/data_guru?page=1&search=1980'));
        $this->assertSame(['Bu Ani'], array_column($body['data'], 'nama'));
        $this->assertSame(self::GURU, $body['data'][0]['id_guru']);
        $this->assertSame(['current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 1, 'from' => 1, 'to' => 1], $body['meta']);
    }

    public function testListsFlagWhetherStudentsAndTeachersHaveLoginAccounts(): void
    {
        $students = $this->body($this->asGuru()->get('/api/data_siswa?page=1'))['data'];
        $this->assertSame(['Andi' => true, 'Budi' => false], array_column($students, 'has_account', 'nama_siswa'));
        $this->assertArrayNotHasKey('akun_id', $students[0]);

        $this->postJson('/api/data_guru', ['nama' => 'Pak Dedi', 'nip' => '1990', 'mata_pelajaran' => 'Fisika'])->assertStatus(201);
        $teachers = $this->body($this->asGuru()->get('/api/data_guru?page=1&search=a'))['data'];
        $this->assertSame(['Bu Ani' => true, 'Pak Dedi' => false], array_column($teachers, 'has_account', 'nama'));
    }

    public function testStudentAccountCannotSeeOtherPeoplesPrivateData(): void
    {
        foreach (['/api/data_siswa', '/api/data_siswa?page=1', '/api/data_guru', '/api/data_guru?page=1'] as $url) {
            $rows = $this->body($this->asSiswa()->get($url))['data'];
            foreach ($rows as $row) {
                foreach (['sn', 'alamat', 'no_telp', 'tanggal_lahir', 'tempat_lahir', 'foto'] as $field) {
                    $this->assertArrayNotHasKey($field, $row, "{$url} membocorkan {$field}");
                }
            }
        }
        // Guru (admin sekolah) tetap melihat data lengkap.
        $this->assertSame('Jl. Rahasia Siswa', $this->body($this->asGuru()->get('/api/data_siswa?page=1&search=andi'))['data'][0]['alamat']);
    }

    // ---- Tambah Siswa -----------------------------------------------------------------

    public function testGuruCanAddStudentWithoutAccount(): void
    {
        $response = $this->postJson('/api/data_siswa', [
            'nama_siswa' => ' Citra ', 'nis' => '1003', 'id_kelas' => '2', 'sn' => 'KARTU-C', 'status' => 'ACTIVE', 'type' => 'siswa',
        ]);

        $response->assertStatus(201);
        $data = $this->body($response)['data'];
        $this->assertSame(['Citra', '1003', 'X RPL 2', 'KARTU-C', null], [$data['nama_siswa'], $data['nis'], $data['nama_kelas'], $data['sn'], $data['id_user']]);
    }

    public function testAddStudentValidation(): void
    {
        $response = $this->postJson('/api/data_siswa', ['nama_siswa' => '', 'nis' => '1001', 'id_kelas' => '99', 'sn' => 'G-01']);

        $response->assertStatus(422);
        $errors = $this->body($response)['errors'];
        $this->assertSame(['nama_siswa', 'nis', 'id_kelas', 'sn'], array_keys($errors));
        $this->assertSame('NIS sudah dipakai siswa lain.', $errors['nis']);
        $this->assertSame('Nomor kartu sudah dipakai.', $errors['sn']);
    }

    // ---- Tambah Guru ------------------------------------------------------------------

    public function testGuruCanAddTeacherWithoutAccount(): void
    {
        $response = $this->postJson('/api/data_guru', ['nama' => 'Pak Dedi', 'nip' => '1990.01', 'mata_pelajaran' => 'Fisika', 'sn' => '']);

        $response->assertStatus(201);
        $data = $this->body($response)['data'];
        $this->assertSame(['Pak Dedi', '1990.01', 'Fisika', null], [$data['nama'], $data['nip'], $data['mata_pelajaran'], $data['sn']]);
        $this->assertSame($data['id_user'], $data['id_guru']);
        $this->assertSame(0, $this->isolatedDatabase->table('users')->where('id_user', $data['id_user'])->countAllResults());
    }

    public function testAddTeacherValidation(): void
    {
        $response = $this->postJson('/api/data_guru', ['nama' => 'X', 'nip' => '1980', 'mata_pelajaran' => '', 'sn' => 5]);

        $response->assertStatus(422);
        $this->assertSame(['nip', 'mata_pelajaran', 'sn'], array_keys($this->body($response)['errors']));
    }

    public function testSiswaCannotAddStudentsOrTeachers(): void
    {
        $this->asSiswa()->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode(['nama_siswa' => 'X', 'nis' => '9', 'id_kelas' => '1']))->post('/api/data_siswa')->assertStatus(403);
        $this->asSiswa()->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode(['nama' => 'X', 'nip' => '9', 'mata_pelajaran' => 'Y']))->post('/api/data_guru')->assertStatus(403);
    }

    // ---- Ubah & hapus Siswa -----------------------------------------------------------

    public function testGuruCanEditStudentAndLinkedAccountStaysInSync(): void
    {
        $response = $this->putJson('/api/data_siswa/1', [
            'nama_siswa' => ' Andi Wijaya ', 'nis' => '1001A', 'id_kelas' => 2, 'sn' => 'S-99', 'status' => 'inactive',
        ]);

        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertSame(['Andi Wijaya', '1001A', 'X RPL 2', 'S-99', 'INACTIVE'],
            [$data['nama_siswa'], $data['nis'], $data['nama_kelas'], $data['sn'], $data['status']]);
        // Nama & kartu di akun ikut berubah: header, lupa password, dan absensi memakai data yang sama.
        $account = $this->isolatedDatabase->table('users')->where('id_user', self::SISWA)->get()->getRowArray();
        $this->assertSame(['Andi Wijaya', 'S-99'], [$account['nama'], $account['sn']]);
        $this->assertNotNull($account['update_date']);
    }

    public function testPartialStudentUpdateKeepsOtherFields(): void
    {
        $this->patchJson('/api/data_siswa/2', ['status' => 'INACTIVE'])->assertStatus(200);
        $row = $this->isolatedDatabase->table('siswa')->where('id_siswa', 2)->get()->getRowArray();
        $this->assertSame(['Budi', '1002', 2, 'INACTIVE'], [$row['nama_siswa'], $row['nis'], (int) $row['id_kelas'], $row['status']]);

        // Form-urlencoded PUT juga diterima.
        $this->asGuru()->withHeaders(['Content-Type' => 'application/x-www-form-urlencoded'])
            ->withBody('nama_siswa=Budi+Santoso')->put('/api/data_siswa/2')->assertStatus(200);
        $this->assertSame('Budi Santoso', $this->isolatedDatabase->table('siswa')->where('id_siswa', 2)->get()->getRow()->nama_siswa);
    }

    public function testEditStudentValidation(): void
    {
        $response = $this->putJson('/api/data_siswa/2', ['nama_siswa' => '', 'nis' => '1001', 'id_kelas' => '99', 'sn' => 'G-01', 'status' => 'LULUS']);
        $response->assertStatus(422);
        $errors = $this->body($response)['errors'];
        $this->assertSame(['nama_siswa', 'nis', 'id_kelas', 'sn', 'status'], array_keys($errors));
        $this->assertSame('NIS sudah dipakai siswa lain.', $errors['nis']);
        $this->assertSame('Nomor kartu sudah dipakai.', $errors['sn']);

        // NIS & kartu milik sendiri bukan bentrok.
        $this->putJson('/api/data_siswa/1', ['nis' => '1001', 'sn' => 'S-01'])->assertStatus(200);
        // Siswa berakun wajib tetap punya kartu; siswa tanpa akun boleh dikosongkan.
        $this->assertArrayHasKey('sn', $this->body($this->putJson('/api/data_siswa/1', ['sn' => '']))['errors']);
        $this->putJson('/api/data_siswa/2', ['sn' => 'KARTU-B'])->assertStatus(200);
        $this->putJson('/api/data_siswa/2', ['sn' => null])->assertStatus(200);
        $this->assertNull($this->isolatedDatabase->table('siswa')->where('id_siswa', 2)->get()->getRow()->sn);

        $this->putJson('/api/data_siswa/2', [])->assertStatus(422);
        $this->putJson('/api/data_siswa/99', ['nama_siswa' => 'X'])->assertStatus(404);
        $this->putJson('/api/data_siswa/abc', ['nama_siswa' => 'X'])->assertStatus(422);
        $this->asGuru()->withHeaders(['Content-Type' => 'application/json'])->withBody('{rusak')->put('/api/data_siswa/1')->assertStatus(400);
    }

    public function testDeleteStudentWithoutAccount(): void
    {
        $this->asGuru()->delete('/api/data_siswa/2')->assertStatus(200);
        $this->assertSame(0, $this->isolatedDatabase->table('siswa')->where('id_siswa', 2)->countAllResults());
        $this->asGuru()->delete('/api/data_siswa/2')->assertStatus(404);
    }

    public function testDeleteStudentAlsoRemovesTheirAccountUnlessAttendanceExists(): void
    {
        $this->isolatedDatabase->query('CREATE TABLE std_absensi (id_absensi TEXT PRIMARY KEY, id_user TEXT, tanggal TEXT)');
        $this->isolatedDatabase->table('absensi')->insert(['id_absensi' => 'a1', 'id_user' => self::SISWA, 'tanggal' => '2026-11-18']);

        $blocked = $this->asGuru()->delete('/api/data_siswa/1');
        $blocked->assertStatus(409);
        $this->assertStringContainsString('Nonaktif', $this->body($blocked)['message']);
        $this->assertSame(1, $this->isolatedDatabase->table('siswa')->where('id_siswa', 1)->countAllResults());

        $this->isolatedDatabase->table('absensi')->truncate();
        $response = $this->asGuru()->delete('/api/data_siswa/1');
        $response->assertStatus(200);
        $this->assertStringContainsString('akun', $this->body($response)['message']);
        $this->assertSame(0, $this->isolatedDatabase->table('siswa')->where('id_siswa', 1)->countAllResults());
        $this->assertSame(0, $this->isolatedDatabase->table('users')->where('id_user', self::SISWA)->countAllResults());
    }

    // ---- Ubah & hapus Guru ------------------------------------------------------------

    public function testGuruCanEditTeacherAndOwnAccountName(): void
    {
        $other = $this->body($this->postJson('/api/data_guru', ['nama' => 'Pak Dedi', 'nip' => '1990', 'mata_pelajaran' => 'Fisika']))['data'];
        $response = $this->putJson('/api/data_guru/' . $other['id_guru'], [
            'nama' => 'Pak Dedi S.', 'nip' => '1990.02', 'mata_pelajaran' => 'Kimia', 'sn' => 'KARTU-D', 'status' => 'INACTIVE',
        ]);
        $response->assertStatus(200);
        $data = $this->body($response)['data'];
        $this->assertSame(['Pak Dedi S.', '1990.02', 'Kimia', 'KARTU-D', 'INACTIVE', $other['id_guru']],
            [$data['nama'], $data['nip'], $data['mata_pelajaran'], $data['sn'], $data['status'], $data['id_guru']]);

        // Guru berakun: nama & kartu di akun ikut berubah.
        $this->putJson('/api/data_guru/' . self::GURU, ['nama' => 'Bu Ani Lestari', 'sn' => 'G-02'])->assertStatus(200);
        $account = $this->isolatedDatabase->table('users')->where('id_user', self::GURU)->get()->getRowArray();
        $this->assertSame(['Bu Ani Lestari', 'G-02'], [$account['nama'], $account['sn']]);
    }

    public function testEditTeacherValidation(): void
    {
        $this->postJson('/api/data_guru', ['nama' => 'Pak Dedi', 'nip' => '1990', 'mata_pelajaran' => 'Fisika', 'sn' => 'KARTU-D'])->assertStatus(201);

        $response = $this->putJson('/api/data_guru/' . self::GURU, ['nama' => ' ', 'nip' => '1990', 'mata_pelajaran' => '', 'sn' => 'KARTU-D', 'status' => 5]);
        $response->assertStatus(422);
        $this->assertSame(['nama', 'mata_pelajaran', 'nip', 'sn', 'status'], array_keys($this->body($response)['errors']));
        $this->assertArrayHasKey('sn', $this->body($this->putJson('/api/data_guru/' . self::GURU, ['sn' => '']))['errors']);
        $this->putJson('/api/data_guru/' . self::GURU, ['nip' => '1980', 'sn' => 'G-01'])->assertStatus(200);
        $this->putJson('/api/data_guru/33333333-3333-4333-8333-333333333333', ['nama' => 'X'])->assertStatus(404);
        $this->putJson('/api/data_guru/bukan-id', ['nama' => 'X'])->assertStatus(422);
    }

    public function testDeleteTeacher(): void
    {
        $other = $this->body($this->postJson('/api/data_guru', ['nama' => 'Pak Dedi', 'nip' => '1990', 'mata_pelajaran' => 'Fisika']))['data'];
        $this->isolatedDatabase->table('kelas')->where('id_kelas', 1)->update(['id_wali_kelas' => $other['id_guru']]);

        $self = $this->asGuru()->delete('/api/data_guru/' . self::GURU);
        $self->assertStatus(409);
        $this->assertSame(1, $this->isolatedDatabase->table('guru')->where('id_user', self::GURU)->countAllResults());

        $this->asGuru()->delete('/api/data_guru/' . $other['id_guru'])->assertStatus(200);
        $this->assertSame(0, $this->isolatedDatabase->table('guru')->where('id_user', $other['id_guru'])->countAllResults());
        $this->assertNull($this->isolatedDatabase->table('kelas')->where('id_kelas', 1)->get()->getRow()->id_wali_kelas,
            'Kelas tetap ada, wali kelasnya dikosongkan.');
        $this->asGuru()->delete('/api/data_guru/' . $other['id_guru'])->assertStatus(404);

        // Guru lain yang punya akun: akunnya ikut dihapus.
        $this->isolatedDatabase->table('users')->insert(['id_user' => '44444444-4444-4444-8444-444444444444', 'username' => 'guru2', 'nama' => 'Bu Rina', 'password' => 'x', 'type' => 'guru']);
        $this->isolatedDatabase->table('guru')->insert(['id_user' => '44444444-4444-4444-8444-444444444444', 'nama' => 'Bu Rina', 'type' => 'guru', 'status' => 'ACTIVE']);
        $this->asGuru()->delete('/api/data_guru/44444444-4444-4444-8444-444444444444')->assertStatus(200);
        $this->assertSame(0, $this->isolatedDatabase->table('users')->where('username', 'guru2')->countAllResults());
    }

    public function testSiswaCannotEditOrDeleteStudentsOrTeachers(): void
    {
        foreach (['/api/data_siswa/2', '/api/data_guru/' . self::GURU] as $url) {
            $this->asSiswa()->withHeaders(['Content-Type' => 'application/json'])->withBody('{"status":"INACTIVE"}')->put($url)->assertStatus(403);
            $this->asSiswa()->delete($url)->assertStatus(403);
        }
        $this->assertSame('ACTIVE', $this->isolatedDatabase->table('siswa')->where('id_siswa', 2)->get()->getRow()->status);
        $this->assertSame(2, $this->isolatedDatabase->table('siswa')->countAllResults());
    }

    // ---- Data dulu, akun nyusul -------------------------------------------------------

    public function testAddedTeacherKeepsHomeroomClassAfterRegistering(): void
    {
        $data = $this->body($this->postJson('/api/data_guru', ['nama' => 'Pak Eko', 'nip' => '2001', 'mata_pelajaran' => 'Kimia', 'sn' => 'KARTU-E']))['data'];
        $this->isolatedDatabase->table('kelas')->where('id_kelas', 2)->update(['id_wali_kelas' => $data['id_user']]);

        $this->withSession([])->withHeaders([])->withBody('')->post('/api/register', [
            'nama' => 'Eko', 'username' => 'eko', 'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'sn' => 'KARTU-E', 'type' => 'guru',
        ])->assertStatus(201);

        $userId = $this->isolatedDatabase->table('users')->where('username', 'eko')->get()->getRow()->id_user;
        $this->assertSame(1, $this->isolatedDatabase->table('guru')->where('nip', '2001')->countAllResults());
        $this->assertSame($userId, $this->isolatedDatabase->table('guru')->where('nip', '2001')->get()->getRow()->id_user);
        // FK wali kelas ikut pindah ke ID akun baru (ON UPDATE CASCADE).
        $this->assertSame($userId, $this->isolatedDatabase->table('kelas')->where('id_kelas', 2)->get()->getRow()->id_wali_kelas);
    }

    public function testCardOfAddedStudentCannotBeUsedToRegisterAsTeacher(): void
    {
        $this->postJson('/api/data_siswa', ['nama_siswa' => 'Fajar', 'nis' => '1004', 'id_kelas' => '1', 'sn' => 'KARTU-F'])->assertStatus(201);

        $this->withSession([])->withHeaders([])->withBody('')->post('/api/register', [
            'nama' => 'Fajar', 'username' => 'fajar', 'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'sn' => 'KARTU-F', 'type' => 'guru',
        ])->assertStatus(422);
        $this->assertSame(0, $this->isolatedDatabase->table('users')->where('username', 'fajar')->countAllResults());
    }

    private function asGuru(): self
    {
        return $this->withHeaders([])->withBody('')->withSession(['auth_user' => ['id_user' => self::GURU, 'type' => 'guru']]);
    }

    private function asSiswa(): self
    {
        return $this->withHeaders([])->withBody('')->withSession(['auth_user' => ['id_user' => self::SISWA, 'type' => 'siswa']]);
    }

    private function postJson(string $url, array $input)
    {
        return $this->asGuru()->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode($input, JSON_THROW_ON_ERROR))->post($url);
    }

    private function putJson(string $url, array $input)
    {
        return $this->asGuru()->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode($input, JSON_THROW_ON_ERROR))->put($url);
    }

    private function patchJson(string $url, array $input)
    {
        return $this->asGuru()->withHeaders(['Content-Type' => 'application/json'])
            ->withBody(json_encode($input, JSON_THROW_ON_ERROR))->patch($url);
    }

    private function body($response): array
    {
        return json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
    }
}
